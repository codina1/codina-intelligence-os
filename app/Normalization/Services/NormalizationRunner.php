<?php

namespace App\Normalization\Services;

use App\Audit\Services\AuditLogger;
use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\NormalizationFailure;
use App\Models\NormalizationRun;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Enums\NormalizationTarget;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class NormalizationRunner
{
    public function __construct(
        private readonly NormalizerRegistry $registry,
        private readonly ContentProjectionService $contentProjection,
        private readonly MetricProjectionService $metricProjection,
        private readonly StagingProvenanceGate $provenance,
        private readonly NormalizationLeaseManager $leases,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{owner: string, generation: int}  $lease
     */
    public function run(NormalizationRun $run, array $lease): NormalizationRun
    {
        $run = $run->fresh();
        $source = DataSource::query()->withoutTenantIsolation()->findOrFail($run->data_source_id);

        $counters = [
            'records_scanned' => 0,
            'entities_created' => 0,
            'entities_updated' => 0,
            'entities_unchanged' => 0,
            'versions_created' => 0,
            'metrics_created' => 0,
            'metrics_updated' => 0,
            'records_rejected' => 0,
        ];

        try {
            DB::transaction(function () use ($run, $lease) {
                $this->leases->assertOwnershipLocked($run, $lease);
                $run->status = NormalizationRunStatus::Running;
                $run->started_at = CarbonImmutable::now();
                $run->save();
            });

            if (! $this->provenance->hasCompletedSync((int) $run->tenant_id, (int) $source->id)) {
                $this->leases->markTerminalIfStillOwner(
                    $run,
                    $lease,
                    NormalizationRunStatus::Failed,
                    [
                        ...$counters,
                        'last_error_code' => NormalizationErrorCode::NoCommittedStaging->value,
                    ],
                    release: true,
                );

                return $run->fresh();
            }

            $completedRunIds = $this->provenance->completedRunIds((int) $run->tenant_id, (int) $source->id);
            $batchSize = (int) config('normalization.batch_size', 100);
            $lastId = 0;
            $processedInBatch = 0;

            while (true) {
                $this->leases->assertOwnership($run, $lease);

                $records = IngestionRecord::query()
                    ->where('tenant_id', $run->tenant_id)
                    ->where('data_source_id', $source->id)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->get();

                if ($records->isEmpty()) {
                    break;
                }

                foreach ($records as $record) {
                    $lastId = (int) $record->id;
                    $counters['records_scanned']++;
                    $this->processRecord($run, $source, $record, $completedRunIds, $lease, $counters);
                    $processedInBatch++;

                    if ($processedInBatch >= 25) {
                        $this->leases->heartbeat($run, $lease);
                        $processedInBatch = 0;
                    }
                }

                $this->leases->heartbeat($run, $lease);
            }

            $status = $counters['records_rejected'] > 0
                ? NormalizationRunStatus::CompletedWithErrors
                : NormalizationRunStatus::Completed;

            $updated = $this->leases->markTerminalIfStillOwner(
                $run,
                $lease,
                $status,
                [
                    ...$counters,
                    'last_error_code' => null,
                ],
                release: true,
            );

            if (! $updated) {
                $this->leases->applyStaleWorkerAbort($run, $lease, $counters);
                $this->auditStale($run, $lease, 'completion_rejected');
            }

            return $run->fresh();
        } catch (DomainException $e) {
            if ($e->getMessage() === NormalizationErrorCode::StaleLease->value) {
                $this->leases->applyStaleWorkerAbort($run, $lease, $counters);
                $this->auditStale($run, $lease, 'lease_lost_during_run');

                return $run->fresh();
            }

            if ($e->getMessage() === NormalizationErrorCode::ForbiddenMode->value
                || $e->getMessage() === NormalizationErrorCode::SourceDisabled->value) {
                $this->leases->markTerminalIfStillOwner(
                    $run,
                    $lease,
                    NormalizationRunStatus::Failed,
                    [
                        ...$counters,
                        'last_error_code' => $e->getMessage(),
                    ],
                    release: true,
                );

                return $run->fresh();
            }

            throw $e;
        } catch (Throwable $e) {
            $this->leases->markTerminalIfStillOwner(
                $run,
                $lease,
                NormalizationRunStatus::Failed,
                [
                    ...$counters,
                    'last_error_code' => NormalizationErrorCode::MapperFailure->value,
                ],
                release: true,
            );

            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $completedRunIds
     * @param  array{owner: string, generation: int}  $lease
     * @param  array<string, int>  $counters
     */
    private function processRecord(
        NormalizationRun $run,
        DataSource $source,
        IngestionRecord $record,
        $completedRunIds,
        array $lease,
        array &$counters,
    ): void {
        // Map / validate outside locks — no FOR UPDATE during mapping.
        $snapshot = $this->provenance->snapshot($record);
        $gate = $this->provenance->evaluate($record, $completedRunIds);
        if (! $gate['ok']) {
            $this->rejectFenced(
                $run,
                $source,
                $record,
                $snapshot,
                $completedRunIds,
                $lease,
                NormalizationErrorCode::from($gate['error_code']),
                $gate['diagnostics'],
                $counters
            );

            return;
        }

        $normalizer = $this->registry->resolve((string) $source->provider, (string) $record->entity_type);

        if ($normalizer === null) {
            $this->rejectFenced(
                $run,
                $source,
                $record,
                $snapshot,
                $completedRunIds,
                $lease,
                NormalizationErrorCode::UnsupportedEntityType,
                ['provider' => $source->provider, 'entity_type' => $record->entity_type],
                $counters
            );

            return;
        }

        $validation = $normalizer->validate($record);
        if (! $validation['ok']) {
            $this->rejectFenced(
                $run,
                $source,
                $record,
                $snapshot,
                $completedRunIds,
                $lease,
                NormalizationErrorCode::from($validation['error_code']),
                $validation['diagnostics'],
                $counters
            );

            return;
        }

        $normalizedContent = null;
        $normalizedMetric = null;

        try {
            if ($normalizer->target() === NormalizationTarget::ContentEntity) {
                $normalizedContent = $normalizer->normalizeContent($record, $source);
            } else {
                $normalizedMetric = $normalizer->normalizeMetric($record, $source);
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'external_id_mismatch') {
                $this->rejectFenced(
                    $run,
                    $source,
                    $record,
                    $snapshot,
                    $completedRunIds,
                    $lease,
                    NormalizationErrorCode::ExternalIdMismatch,
                    ['external_id_hash' => $record->external_id_hash],
                    $counters
                );

                return;
            }

            throw $e;
        }

        // Final ownership + provenance + projection in one shared transaction.
        // Lock order: active_scopes → data_sources → ingestion_records → entities/metrics.
        DB::transaction(function () use (
            $run,
            $source,
            $record,
            $snapshot,
            $completedRunIds,
            $lease,
            $normalizedContent,
            $normalizedMetric,
            &$counters,
        ) {
            $this->leases->assertOwnershipLocked($run, $lease);

            $freshSource = DataSource::query()->withoutTenantIsolation()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            $modeValue = (string) ($freshSource->getAttributes()['mode'] ?? '');
            if ($modeValue !== DataSourceMode::Mock->value) {
                throw new DomainException(NormalizationErrorCode::ForbiddenMode->value);
            }
            if ($freshSource->status === DataSourceStatus::Disabled) {
                throw new DomainException(NormalizationErrorCode::SourceDisabled->value);
            }

            $freshRecord = IngestionRecord::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->first();

            if ($freshRecord === null) {
                $counters['records_rejected']++;
                NormalizationFailure::query()->create([
                    'tenant_id' => $run->tenant_id,
                    'normalization_run_id' => $run->id,
                    'ingestion_record_id' => $record->id,
                    'error_code' => NormalizationErrorCode::StagingMutated->value,
                    'safe_diagnostics_json' => ['reason' => 'staging_row_missing'],
                    'created_at' => CarbonImmutable::now(),
                ]);

                return;
            }

            $gate = $this->provenance->evaluate($freshRecord, $completedRunIds, $snapshot);
            if (! $gate['ok']) {
                $counters['records_rejected']++;
                NormalizationFailure::query()->create([
                    'tenant_id' => $run->tenant_id,
                    'normalization_run_id' => $run->id,
                    'ingestion_record_id' => $freshRecord->id,
                    'error_code' => $gate['error_code'],
                    'safe_diagnostics_json' => $this->safeDiagnostics($gate['diagnostics']),
                    'created_at' => CarbonImmutable::now(),
                ]);

                return;
            }

            if ($normalizedContent !== null) {
                $result = $this->contentProjection->project($freshSource, $freshRecord, $normalizedContent);
                if ($result['outcome'] === 'created') {
                    $counters['entities_created']++;
                    $counters['versions_created']++;
                } elseif ($result['outcome'] === 'updated') {
                    $counters['entities_updated']++;
                    $counters['versions_created']++;
                } else {
                    $counters['entities_unchanged']++;
                }

                return;
            }

            $result = $this->metricProjection->project($freshSource, $freshRecord, $normalizedMetric);
            if ($result['outcome'] === 'created') {
                $counters['metrics_created']++;
            } elseif ($result['outcome'] === 'updated') {
                $counters['metrics_updated']++;
            }
        });
    }

    /**
     * @param  array{id: int, payload_hash: string|null, last_sync_run_id: int|null, last_changed_at: string|null}  $snapshot
     * @param  \Illuminate\Support\Collection<int, int>  $completedRunIds
     * @param  array{owner: string, generation: int}  $lease
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, int>  $counters
     */
    private function rejectFenced(
        NormalizationRun $run,
        DataSource $source,
        IngestionRecord $record,
        array $snapshot,
        $completedRunIds,
        array $lease,
        NormalizationErrorCode $code,
        array $diagnostics,
        array &$counters,
    ): void {
        DB::transaction(function () use (
            $run,
            $record,
            $snapshot,
            $completedRunIds,
            $lease,
            $code,
            $diagnostics,
            &$counters,
        ) {
            $this->leases->assertOwnershipLocked($run, $lease);

            $freshRecord = IngestionRecord::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->first();

            // Re-check staging mutation even on reject path so failures stay consistent.
            if ($freshRecord !== null) {
                $gate = $this->provenance->evaluate($freshRecord, $completedRunIds, $snapshot);
                if (! $gate['ok'] && $gate['error_code'] === NormalizationErrorCode::StagingMutated->value) {
                    $code = NormalizationErrorCode::StagingMutated;
                    $diagnostics = $gate['diagnostics'];
                }
            }

            $counters['records_rejected']++;

            NormalizationFailure::query()->create([
                'tenant_id' => $run->tenant_id,
                'normalization_run_id' => $run->id,
                'ingestion_record_id' => $record->id,
                'error_code' => $code->value,
                'safe_diagnostics_json' => $this->safeDiagnostics($diagnostics),
                'created_at' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * @param  array{owner: string, generation: int}  $lease
     */
    private function auditStale(NormalizationRun $run, array $lease, string $reason): void
    {
        $this->auditLogger->log('normalization.stale_lease_abort', null, $run, [
            'reason' => $reason,
            'lease_owner' => $lease['owner'],
            'generation' => $lease['generation'],
            'error_code' => NormalizationErrorCode::StaleLease->value,
        ], (int) $run->tenant_id);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    private function safeDiagnostics(array $diagnostics): array
    {
        $blocked = [
            'password', 'token', 'secret', 'api_key', 'api_token', 'authorization',
            'client_secret', 'refresh_token', 'access_token', 'credentials', 'payload', 'raw',
        ];

        $safe = [];
        foreach ($diagnostics as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_array($value)) {
                $safe[$key] = array_map(
                    static fn ($v) => is_scalar($v) || $v === null ? $v : '[omitted]',
                    $value
                );
            }
        }

        return $safe;
    }
}
