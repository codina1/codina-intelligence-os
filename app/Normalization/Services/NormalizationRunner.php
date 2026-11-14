<?php

namespace App\Normalization\Services;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\NormalizationFailure;
use App\Models\NormalizationRun;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Enums\NormalizationTarget;
use Carbon\CarbonImmutable;
use DomainException;
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
    ) {}

    /**
     * @param  array{owner: string, generation: int}  $lease
     */
    public function run(NormalizationRun $run, array $lease): NormalizationRun
    {
        $run = $run->fresh();
        $source = DataSource::query()->withoutTenantIsolation()->findOrFail($run->data_source_id);

        $this->leases->assertOwnershipLocked($run, $lease);

        $run->status = NormalizationRunStatus::Running;
        $run->started_at = CarbonImmutable::now();
        $run->save();

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
            if (! $this->provenance->hasCompletedSync((int) $run->tenant_id, (int) $source->id)) {
                $this->failRun($run, NormalizationErrorCode::NoCommittedStaging, $counters);
                $this->leases->release($run, $lease);

                return $run->fresh();
            }

            $completedRunIds = $this->provenance->completedRunIds((int) $run->tenant_id, (int) $source->id);
            $batchSize = (int) config('normalization.batch_size', 100);
            $lastId = 0;
            $processedInBatch = 0;

            while (true) {
                $this->leases->assertOwnershipLocked($run, $lease);

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
                    $this->processRecord($run, $source, $record, $completedRunIds, $counters);
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

            $this->leases->assertOwnershipLocked($run, $lease);

            $run->fill([
                ...$counters,
                'status' => $status,
                'finished_at' => CarbonImmutable::now(),
                'last_error_code' => null,
            ]);
            $run->save();

            $this->leases->release($run, $lease);

            return $run->fresh();
        } catch (DomainException $e) {
            if ($e->getMessage() === NormalizationErrorCode::StaleLease->value) {
                $run->fill([
                    ...$counters,
                    'status' => NormalizationRunStatus::Failed,
                    'finished_at' => CarbonImmutable::now(),
                    'last_error_code' => NormalizationErrorCode::StaleLease->value,
                ]);
                $run->save();

                return $run->fresh();
            }

            throw $e;
        } catch (Throwable $e) {
            $run->fill([
                ...$counters,
                'status' => NormalizationRunStatus::Failed,
                'finished_at' => CarbonImmutable::now(),
                'last_error_code' => NormalizationErrorCode::MapperFailure->value,
            ]);
            $run->save();
            $this->leases->release($run, $lease);

            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $completedRunIds
     * @param  array<string, int>  $counters
     */
    private function processRecord(
        NormalizationRun $run,
        DataSource $source,
        IngestionRecord $record,
        $completedRunIds,
        array &$counters,
    ): void {
        $gate = $this->provenance->evaluate($record, $completedRunIds);
        if (! $gate['ok']) {
            $this->reject(
                $run,
                $record,
                NormalizationErrorCode::from($gate['error_code']),
                $gate['diagnostics'],
                $counters
            );

            return;
        }

        $normalizer = $this->registry->resolve((string) $source->provider, (string) $record->entity_type);

        if ($normalizer === null) {
            $this->reject(
                $run,
                $record,
                NormalizationErrorCode::UnsupportedEntityType,
                ['provider' => $source->provider, 'entity_type' => $record->entity_type],
                $counters
            );

            return;
        }

        $validation = $normalizer->validate($record);
        if (! $validation['ok']) {
            $this->reject(
                $run,
                $record,
                NormalizationErrorCode::from($validation['error_code']),
                $validation['diagnostics'],
                $counters
            );

            return;
        }

        try {
            if ($normalizer->target() === NormalizationTarget::ContentEntity) {
                $normalized = $normalizer->normalizeContent($record, $source);
                $result = $this->contentProjection->project($source, $record, $normalized);

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

            $normalized = $normalizer->normalizeMetric($record, $source);
            $result = $this->metricProjection->project($source, $record, $normalized);

            if ($result['outcome'] === 'created') {
                $counters['metrics_created']++;
            } elseif ($result['outcome'] === 'updated') {
                $counters['metrics_updated']++;
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'external_id_mismatch') {
                $this->reject(
                    $run,
                    $record,
                    NormalizationErrorCode::ExternalIdMismatch,
                    ['external_id_hash' => $record->external_id_hash],
                    $counters
                );

                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, int>  $counters
     */
    private function reject(
        NormalizationRun $run,
        IngestionRecord $record,
        NormalizationErrorCode $code,
        array $diagnostics,
        array &$counters,
    ): void {
        $counters['records_rejected']++;

        NormalizationFailure::query()->create([
            'tenant_id' => $run->tenant_id,
            'normalization_run_id' => $run->id,
            'ingestion_record_id' => $record->id,
            'error_code' => $code->value,
            'safe_diagnostics_json' => $this->safeDiagnostics($diagnostics),
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function failRun(NormalizationRun $run, NormalizationErrorCode $code, array $counters): void
    {
        $run->fill([
            ...$counters,
            'status' => NormalizationRunStatus::Failed,
            'finished_at' => CarbonImmutable::now(),
            'last_error_code' => $code->value,
        ]);
        $run->save();
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
