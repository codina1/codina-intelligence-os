<?php

namespace App\Synchronization\Services;

use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Models\DataSource;
use App\Models\SyncAttempt;
use App\Models\SyncRun;
use App\Synchronization\DTOs\SyncCursor;
use App\Synchronization\DTOs\SyncResult;
use App\Synchronization\Enums\SyncErrorCode;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Tenancy\Services\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncRunner
{
    public function __construct(
        private readonly SyncLeaseManager $leases,
        private readonly CheckpointManager $checkpoints,
        private readonly IngestionService $ingestion,
        private readonly SyncPageAdapter $pages,
        private readonly SyncErrorClassifier $errors,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array{owner: string, generation: int}|null  $lease
     */
    public function run(SyncRun $run, ?array $lease = null): SyncResult
    {
        $attempt = null;

        try {
            $lease ??= $this->leases->acquire($run);
            $owner = $lease['owner'];
            $generation = $lease['generation'];

            $this->transition($run, SyncRunStatus::Running, [
                'started_at' => $run->started_at ?? now(),
                'lease_owner' => $owner,
                'generation' => $generation,
            ]);

            $attemptNumber = ((int) SyncAttempt::query()->withoutTenantIsolation()
                ->where('sync_run_id', $run->id)
                ->max('attempt_number')) + 1;

            $attempt = SyncAttempt::query()->create([
                'tenant_id' => $run->tenant_id,
                'sync_run_id' => $run->id,
                'attempt_number' => $attemptNumber,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $source = DataSource::query()->withoutTenantIsolation()->find($run->data_source_id);
            if ($source === null) {
                return $this->fail($run, $attempt, SyncErrorCode::InvalidConfiguration, 'Data source missing.', $owner, $generation);
            }

            if ((int) $source->tenant_id !== (int) $run->tenant_id) {
                return $this->fail($run, $attempt, SyncErrorCode::TenantMismatch, 'Tenant ownership mismatch.', $owner, $generation);
            }

            if ($source->status === DataSourceStatus::Disabled) {
                return $this->fail($run, $attempt, SyncErrorCode::SourceDisabled, 'Data source is disabled.', $owner, $generation);
            }

            if ($source->mode !== DataSourceMode::Mock) {
                return $this->fail($run, $attempt, SyncErrorCode::NonMockSource, 'Only mock sources may sync.', $owner, $generation);
            }

            $checkpoint = $this->checkpoints->getOrCreate($run);
            $cursor = $this->checkpoints->startingCursor($run, $checkpoint);
            $seenFingerprints = [];
            $maxPages = (int) config('sync.max_pages_per_run', 100);
            $pagesCompleted = (int) $run->pages_completed;
            $schemaVersion = (string) config('codina.schema_version', '1.0');

            $totals = [
                'received' => (int) $run->rows_received,
                'inserted' => (int) $run->rows_inserted,
                'updated' => (int) $run->rows_updated,
                'unchanged' => (int) $run->rows_unchanged,
                'rejected' => (int) $run->rows_rejected,
                'duplicate_in_batch' => (int) $run->rows_duplicate_in_batch,
            ];

            for ($i = 0; $i < $maxPages; $i++) {
                if ($this->leases->isCancelRequested($run)) {
                    return $this->cancel($run, $attempt, $owner, $generation, $totals, $pagesCompleted);
                }

                if (! $this->leases->heartbeat($run, $owner, $generation)) {
                    return $this->fail($run, $attempt, SyncErrorCode::LeaseLost, 'Lease lost before page fetch.', $owner, $generation, release: false);
                }

                $this->leases->assertOwnership($run, $owner, $generation);

                $page = $this->pages->fetchPage(
                    $source,
                    $run->entity_type,
                    $run->sync_type,
                    $cursor,
                    $run->options_json ?? [],
                );

                if ($page->sourceMode !== 'mock') {
                    return $this->fail($run, $attempt, SyncErrorCode::ForbiddenMode, 'Non-mock page refused.', $owner, $generation);
                }

                if ($page->isError()) {
                    $code = $this->errors->classify($page->errorCode);

                    return $this->handlePageError($run, $attempt, $code, $page->errorMessage ?? $code->value, $owner, $generation, $page->retryAfterSeconds, $totals, $pagesCompleted);
                }

                $fingerprint = hash('sha256', json_encode([
                    'before' => $page->cursorBefore,
                    'after' => $page->cursorAfter,
                    'has_more' => $page->hasMore,
                ], JSON_THROW_ON_ERROR));

                if (isset($seenFingerprints[$fingerprint]) && $page->hasMore) {
                    return $this->fail($run, $attempt, SyncErrorCode::CursorLoop, 'Repeated cursor detected; stopping.', $owner, $generation);
                }
                $seenFingerprints[$fingerprint] = true;

                if ($page->cursorAfter !== null && SyncCursor::fromArray($page->cursorAfter)->fingerprint() === $cursor->fingerprint() && $page->hasMore) {
                    return $this->fail($run, $attempt, SyncErrorCode::CursorLoop, 'Cursor did not advance.', $owner, $generation);
                }

                $pageNumber = $cursor->page();

                $commitResult = DB::transaction(function () use (
                    $run,
                    $owner,
                    $generation,
                    $source,
                    $pageNumber,
                    $page,
                    $schemaVersion,
                    $checkpoint,
                    &$totals,
                    &$pagesCompleted,
                ) {
                    $this->leases->assertOwnership($run, $owner, $generation);
                    if ($this->leases->isCancelRequested($run)) {
                        throw new DomainException(SyncErrorCode::Cancelled->value);
                    }

                    $freshSource = DataSource::query()->withoutTenantIsolation()->lockForUpdate()->findOrFail($source->id);
                    if ($freshSource->status === DataSourceStatus::Disabled) {
                        throw new DomainException(SyncErrorCode::SourceDisabled->value);
                    }
                    if ($freshSource->mode !== DataSourceMode::Mock) {
                        throw new DomainException(SyncErrorCode::NonMockSource->value);
                    }

                    $result = $this->ingestion->processPage(
                        $run,
                        $pageNumber,
                        $page->records,
                        $page->cursorBefore,
                        $page->cursorAfter,
                        $freshSource->provider,
                        $schemaVersion,
                    );

                    if (! $result['already_committed']) {
                        $totals['received'] += $result['received'];
                        $totals['inserted'] += $result['inserted'];
                        $totals['updated'] += $result['updated'];
                        $totals['unchanged'] += $result['unchanged'];
                        $totals['rejected'] += $result['rejected'];
                        $totals['duplicate_in_batch'] += $result['duplicate_in_batch'];
                    }
                    // already_committed: same run/page replay after a crash window — counters stay as-is.

                    $advanceWatermark = $run->sync_type === SyncType::Incremental && ! $page->hasMore;
                    $watermark = $advanceWatermark ? [
                        'cursor' => $page->cursorAfter,
                        'committed_at' => now()->toIso8601String(),
                        'simulated' => true,
                    ] : null;

                    $this->checkpoints->commitPage(
                        $run,
                        $checkpoint,
                        $pageNumber,
                        $page->cursorAfter,
                        $watermark,
                        $advanceWatermark,
                    );

                    $pagesCompleted++;

                    $run->forceFill([
                        'rows_received' => $totals['received'],
                        'rows_inserted' => $totals['inserted'],
                        'rows_updated' => $totals['updated'],
                        'rows_unchanged' => $totals['unchanged'],
                        'rows_rejected' => $totals['rejected'],
                        'rows_duplicate_in_batch' => $totals['duplicate_in_batch'],
                        'pages_completed' => $pagesCompleted,
                    ])->save();

                    return $result;
                });

                $checkpoint = $checkpoint->fresh();

                if (! $page->hasMore) {
                    $this->leases->release($run, $owner, $generation);
                    $this->finishAttempt($attempt, 'completed');
                    $this->transition($run, SyncRunStatus::Completed, [
                        'finished_at' => now(),
                        'completeness_status' => 'complete',
                        'last_error_code' => null,
                        'last_error_summary' => null,
                        'rows_received' => $totals['received'],
                        'rows_inserted' => $totals['inserted'],
                        'rows_updated' => $totals['updated'],
                        'rows_unchanged' => $totals['unchanged'],
                        'rows_rejected' => $totals['rejected'],
                        'rows_duplicate_in_batch' => $totals['duplicate_in_batch'],
                        'pages_completed' => $pagesCompleted,
                    ]);

                    return new SyncResult(
                        status: SyncRunStatus::Completed,
                        rowsReceived: $totals['received'],
                        rowsInserted: $totals['inserted'],
                        rowsUpdated: $totals['updated'],
                        rowsUnchanged: $totals['unchanged'],
                        rowsRejected: $totals['rejected'],
                        pagesCompleted: $pagesCompleted,
                        completenessStatus: 'complete',
                    );
                }

                $cursor = SyncCursor::fromArray($page->cursorAfter);
                unset($commitResult);
            }

            return $this->fail($run, $attempt, SyncErrorCode::MaxPagesExceeded, 'Maximum pages per run exceeded.', $owner, $generation, totals: $totals, pagesCompleted: $pagesCompleted);
        } catch (DomainException $e) {
            $code = SyncErrorCode::tryFrom($e->getMessage()) ?? SyncErrorCode::TemporaryFailure;
            if ($code === SyncErrorCode::Cancelled && $attempt !== null) {
                return $this->cancel($run, $attempt, $run->lease_owner ?? 'unknown', (int) $run->generation, [
                    'received' => (int) $run->rows_received,
                    'inserted' => (int) $run->rows_inserted,
                    'updated' => (int) $run->rows_updated,
                    'unchanged' => (int) $run->rows_unchanged,
                    'rejected' => (int) $run->rows_rejected,
                    'duplicate_in_batch' => (int) $run->rows_duplicate_in_batch,
                ], (int) $run->pages_completed);
            }

            return $this->fail(
                $run,
                $attempt,
                $code,
                $e->getMessage(),
                $run->lease_owner,
                (int) $run->generation,
                release: $code !== SyncErrorCode::LeaseLost && $code !== SyncErrorCode::ActiveRunConflict,
            );
        } catch (Throwable $e) {
            $code = $this->errors->classify(null, $e);

            return $this->fail(
                $run,
                $attempt,
                $code,
                'Sync runner exception: '.class_basename($e),
                $run->lease_owner,
                (int) $run->generation,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(SyncRun $run, SyncRunStatus $next, array $attributes = []): void
    {
        $run->refresh();
        $current = $run->status;
        if ($current !== $next && ! $current->canTransitionTo($next)) {
            throw new DomainException(SyncErrorCode::InvalidTransition->value);
        }

        $run->forceFill(array_merge(['status' => $next], $attributes))->save();
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function handlePageError(
        SyncRun $run,
        ?SyncAttempt $attempt,
        SyncErrorCode $code,
        string $summary,
        string $owner,
        int $generation,
        ?int $retryAfter,
        array $totals,
        int $pagesCompleted,
    ): SyncResult {
        if ($code->isRetryable()) {
            $maxRetries = (int) config('sync.max_retries', 3);
            if ((int) $run->retry_count < $maxRetries) {
                $delay = $this->errors->backoffSeconds(((int) $run->retry_count) + 1, $retryAfter, 'run-'.$run->id);
                $this->finishAttempt($attempt, 'retryable', $code->value, $summary, now()->addSeconds($delay));
                $this->leases->release($run, $owner, $generation);
                $this->transition($run, SyncRunStatus::Partial, [
                    'retry_count' => ((int) $run->retry_count) + 1,
                    'last_error_code' => $code->value,
                    'last_error_summary' => $summary,
                    'completeness_status' => 'partial',
                    'rows_received' => $totals['received'],
                    'rows_inserted' => $totals['inserted'],
                    'rows_updated' => $totals['updated'],
                    'rows_unchanged' => $totals['unchanged'],
                    'rows_rejected' => $totals['rejected'],
                    'rows_duplicate_in_batch' => $totals['duplicate_in_batch'],
                    'pages_completed' => $pagesCompleted,
                ]);

                return new SyncResult(
                    status: SyncRunStatus::Partial,
                    rowsReceived: $totals['received'],
                    rowsInserted: $totals['inserted'],
                    rowsUpdated: $totals['updated'],
                    rowsUnchanged: $totals['unchanged'],
                    rowsRejected: $totals['rejected'],
                    pagesCompleted: $pagesCompleted,
                    errorCode: $code->value,
                    errorSummary: $summary,
                    completenessStatus: 'partial',
                );
            }

            $code = SyncErrorCode::RetryBudgetExhausted;
            $summary = 'Retry budget exhausted after transient failures.';
        }

        return $this->fail($run, $attempt, $code, $summary, $owner, $generation, totals: $totals, pagesCompleted: $pagesCompleted);
    }

    /**
     * @param  array<string, int>|null  $totals
     */
    private function fail(
        SyncRun $run,
        ?SyncAttempt $attempt,
        SyncErrorCode $code,
        string $summary,
        ?string $owner,
        int $generation,
        bool $release = true,
        ?array $totals = null,
        ?int $pagesCompleted = null,
    ): SyncResult {
        $this->finishAttempt($attempt, 'failed', $code->value, $summary);

        if ($release && $owner !== null) {
            $this->leases->release($run, $owner, $generation);
        }

        $attributes = [
            'finished_at' => now(),
            'last_error_code' => $code->value,
            'last_error_summary' => $summary,
            'completeness_status' => 'failed',
        ];
        if ($totals !== null) {
            $attributes = array_merge($attributes, [
                'rows_received' => $totals['received'],
                'rows_inserted' => $totals['inserted'],
                'rows_updated' => $totals['updated'],
                'rows_unchanged' => $totals['unchanged'],
                'rows_rejected' => $totals['rejected'],
                'rows_duplicate_in_batch' => $totals['duplicate_in_batch'],
                'pages_completed' => $pagesCompleted ?? $run->pages_completed,
            ]);
        }

        if ($run->status->canTransitionTo(SyncRunStatus::Failed) || $run->status === SyncRunStatus::Failed) {
            $this->transition($run, SyncRunStatus::Failed, $attributes);
        } else {
            $run->forceFill($attributes)->save();
        }

        return new SyncResult(
            status: SyncRunStatus::Failed,
            rowsReceived: (int) $run->rows_received,
            rowsInserted: (int) $run->rows_inserted,
            rowsUpdated: (int) $run->rows_updated,
            rowsUnchanged: (int) $run->rows_unchanged,
            rowsRejected: (int) $run->rows_rejected,
            pagesCompleted: (int) $run->pages_completed,
            errorCode: $code->value,
            errorSummary: $summary,
            completenessStatus: 'failed',
        );
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function cancel(
        SyncRun $run,
        ?SyncAttempt $attempt,
        string $owner,
        int $generation,
        array $totals,
        int $pagesCompleted,
    ): SyncResult {
        $this->finishAttempt($attempt, 'cancelled', SyncErrorCode::Cancelled->value, 'Cancellation acknowledged.');
        $this->leases->release($run, $owner, $generation);

        $this->transition($run, SyncRunStatus::Cancelled, [
            'finished_at' => now(),
            'cancelled_at' => $run->cancelled_at ?? now(),
            'last_error_code' => SyncErrorCode::Cancelled->value,
            'last_error_summary' => 'Cancellation acknowledged.',
            'completeness_status' => 'cancelled',
            'rows_received' => $totals['received'],
            'rows_inserted' => $totals['inserted'],
            'rows_updated' => $totals['updated'],
            'rows_unchanged' => $totals['unchanged'],
            'rows_rejected' => $totals['rejected'],
            'rows_duplicate_in_batch' => $totals['duplicate_in_batch'],
            'pages_completed' => $pagesCompleted,
        ]);

        return new SyncResult(
            status: SyncRunStatus::Cancelled,
            rowsReceived: $totals['received'],
            rowsInserted: $totals['inserted'],
            rowsUpdated: $totals['updated'],
            rowsUnchanged: $totals['unchanged'],
            rowsRejected: $totals['rejected'],
            pagesCompleted: $pagesCompleted,
            errorCode: SyncErrorCode::Cancelled->value,
            errorSummary: 'Cancellation acknowledged.',
            completenessStatus: 'cancelled',
        );
    }

    private function finishAttempt(
        ?SyncAttempt $attempt,
        string $status,
        ?string $errorCode = null,
        ?string $errorSummary = null,
        mixed $nextRetryAt = null,
    ): void {
        if ($attempt === null) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error_code' => $errorCode,
            'error_summary' => $errorSummary,
            'next_retry_at' => $nextRetryAt,
        ])->save();
    }
}
