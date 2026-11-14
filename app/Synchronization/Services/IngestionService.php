<?php

namespace App\Synchronization\Services;

use App\Models\IngestionBatch;
use App\Models\IngestionRecord;
use App\Models\SyncRun;
use App\Synchronization\Enums\SyncErrorCode;
use App\Synchronization\Validators\SyncRecordValidator;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class IngestionService
{
    public function __construct(private readonly SyncRecordValidator $validator) {}

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>|null  $cursorBefore
     * @param  array<string, mixed>|null  $cursorAfter
     * @return array{
     *   batch: IngestionBatch,
     *   received: int,
     *   accepted: int,
     *   inserted: int,
     *   updated: int,
     *   unchanged: int,
     *   rejected: int,
     *   duplicate_in_batch: int,
     *   already_committed: bool
     * }
     */
    public function processPage(
        SyncRun $run,
        int $pageNumber,
        array $records,
        ?array $cursorBefore,
        ?array $cursorAfter,
        string $provider,
        string $schemaVersion,
    ): array {
        $payloadHash = $this->validator->payloadHash([
            'page' => $pageNumber,
            'records' => $records,
            'cursor_before' => $cursorBefore,
            'cursor_after' => $cursorAfter,
        ]);

        // Deterministic per-run page key: retries of the same run+page+payload are
        // idempotent; a new run must re-upsert so unchanged/updated counters stay accurate.
        $commitKey = hash('sha256', implode('|', [
            (string) $run->id,
            (string) $run->data_source_id,
            $run->entity_type,
            $run->scope_key,
            (string) $pageNumber,
            $payloadHash,
        ]));

        $existing = IngestionBatch::query()->withoutTenantIsolation()
            ->where('commit_key', $commitKey)
            ->where('sync_run_id', $run->id)
            ->where('status', 'committed')
            ->first();

        if ($existing !== null) {
            return [
                'batch' => $existing,
                'received' => (int) $existing->rows_received,
                'accepted' => (int) $existing->rows_accepted,
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => (int) $existing->rows_accepted,
                'rejected' => (int) $existing->rows_rejected,
                'duplicate_in_batch' => 0,
                'already_committed' => true,
            ];
        }

        $seenIds = [];
        $accepted = [];
        $rejected = 0;
        $duplicateInBatch = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                $rejected++;

                continue;
            }

            $result = $this->validator->validate($provider, $run->entity_type, $record, $schemaVersion);
            if (! $result['ok']) {
                $rejected++;

                continue;
            }

            $externalId = (string) $result['external_id'];
            if (isset($seenIds[$externalId])) {
                $duplicateInBatch++;

                continue;
            }
            $seenIds[$externalId] = true;
            $accepted[] = [
                'external_id' => $externalId,
                'payload' => $record,
            ];
        }

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $now = now();

        $batch = DB::transaction(function () use (
            $run,
            $pageNumber,
            $cursorBefore,
            $cursorAfter,
            $payloadHash,
            $commitKey,
            $records,
            $accepted,
            $rejected,
            $now,
            &$inserted,
            &$updated,
            &$unchanged,
        ) {
            $batch = IngestionBatch::query()->create([
                'tenant_id' => $run->tenant_id,
                'data_source_id' => $run->data_source_id,
                'sync_run_id' => $run->id,
                'entity_type' => $run->entity_type,
                'scope_key' => $run->scope_key,
                'page_number' => $pageNumber,
                'cursor_before_json' => $cursorBefore,
                'cursor_after_json' => $cursorAfter,
                'payload_hash' => $payloadHash,
                'commit_key' => $commitKey,
                'status' => 'processing',
                'rows_received' => count($records),
                'rows_accepted' => count($accepted),
                'rows_rejected' => $rejected,
                'committed_at' => null,
            ]);

            foreach ($accepted as $item) {
                $externalId = $item['external_id'];
                $payload = $item['payload'];
                $externalIdHash = $this->validator->externalIdHash($externalId);
                $recordPayloadHash = $this->validator->payloadHash($payload);

                $existingRecord = IngestionRecord::query()->withoutTenantIsolation()
                    ->where('data_source_id', $run->data_source_id)
                    ->where('entity_type', $run->entity_type)
                    ->where('external_id_hash', $externalIdHash)
                    ->lockForUpdate()
                    ->first();

                if ($existingRecord !== null) {
                    if ($existingRecord->external_id !== $externalId) {
                        throw new DomainException(SyncErrorCode::HashCollision->value);
                    }

                    if ($existingRecord->payload_hash === $recordPayloadHash) {
                        $existingRecord->forceFill([
                            'last_seen_at' => $now,
                            'last_sync_run_id' => $run->id,
                        ])->save();
                        $unchanged++;
                    } else {
                        $existingRecord->forceFill([
                            'payload_hash' => $recordPayloadHash,
                            'payload_json' => $payload,
                            'last_seen_at' => $now,
                            'last_changed_at' => $now,
                            'last_sync_run_id' => $run->id,
                        ])->save();
                        $updated++;
                    }

                    continue;
                }

                try {
                    IngestionRecord::query()->create([
                        'tenant_id' => $run->tenant_id,
                        'data_source_id' => $run->data_source_id,
                        'entity_type' => $run->entity_type,
                        'external_id' => $externalId,
                        'external_id_hash' => $externalIdHash,
                        'payload_hash' => $recordPayloadHash,
                        'payload_json' => $payload,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'last_changed_at' => $now,
                        'last_sync_run_id' => $run->id,
                    ]);
                    $inserted++;
                } catch (QueryException $e) {
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    $raced = IngestionRecord::query()->withoutTenantIsolation()
                        ->where('data_source_id', $run->data_source_id)
                        ->where('entity_type', $run->entity_type)
                        ->where('external_id_hash', $externalIdHash)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($raced->external_id !== $externalId) {
                        throw new DomainException(SyncErrorCode::HashCollision->value);
                    }

                    if ($raced->payload_hash === $recordPayloadHash) {
                        $raced->forceFill([
                            'last_seen_at' => $now,
                            'last_sync_run_id' => $run->id,
                        ])->save();
                        $unchanged++;
                    } else {
                        $raced->forceFill([
                            'payload_hash' => $recordPayloadHash,
                            'payload_json' => $payload,
                            'last_seen_at' => $now,
                            'last_changed_at' => $now,
                            'last_sync_run_id' => $run->id,
                        ])->save();
                        $updated++;
                    }
                }
            }

            $batch->forceFill([
                'status' => 'committed',
                'committed_at' => $now,
            ])->save();

            return $batch;
        });

        return [
            'batch' => $batch,
            'received' => count($records),
            'accepted' => count($accepted),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'rejected' => $rejected,
            'duplicate_in_batch' => $duplicateInBatch,
            'already_committed' => false,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || str_contains($e->getMessage(), 'Duplicate');
    }
}
