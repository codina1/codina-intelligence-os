<?php

namespace App\Normalization\Services;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\MetricObservation;
use App\Normalization\DTOs\NormalizedMetric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetricProjectionService
{
    /**
     * @return array{outcome: string, observation: MetricObservation}
     */
    public function project(
        DataSource $source,
        IngestionRecord $record,
        NormalizedMetric $normalized,
    ): array {
        return DB::transaction(function () use ($source, $record, $normalized) {
            $observation = MetricObservation::query()
                ->where('data_source_id', $source->id)
                ->where('observation_identity_hash', $normalized->observationIdentityHash)
                ->lockForUpdate()
                ->first();

            if ($observation === null) {
                $observation = MetricObservation::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'tenant_id' => $source->tenant_id,
                    'site_id' => $source->site_id,
                    'data_source_id' => $source->id,
                    'source_ingestion_record_id' => $record->id,
                    'provider' => $normalized->provider,
                    'metric_key' => $normalized->metricKey,
                    'metric_value' => $normalized->metricValue,
                    'unit' => $normalized->unit,
                    'dimensions_json' => $normalized->dimensions,
                    'observation_identity_hash' => $normalized->observationIdentityHash,
                    'period_start' => $normalized->periodStart,
                    'period_end' => $normalized->periodEnd,
                    'observed_at' => $normalized->observedAt,
                    'source_payload_hash' => $normalized->sourcePayloadHash,
                    'mapper_version' => $normalized->mapperVersion,
                ]);

                return ['outcome' => 'created', 'observation' => $observation];
            }

            $unchanged = $observation->source_payload_hash === $normalized->sourcePayloadHash
                && $observation->metric_key === $normalized->metricKey
                && $this->metricValuesEqual($observation->metric_value, $normalized->metricValue)
                && $observation->unit === $normalized->unit
                && $observation->dimensions_json == $normalized->dimensions;

            if ($unchanged) {
                if ((int) $observation->source_ingestion_record_id !== (int) $record->id) {
                    $observation->source_ingestion_record_id = $record->id;
                    $observation->save();
                }

                return ['outcome' => 'unchanged', 'observation' => $observation->fresh()];
            }

            $observation->fill([
                'source_ingestion_record_id' => $record->id,
                'metric_key' => $normalized->metricKey,
                'metric_value' => $normalized->metricValue,
                'unit' => $normalized->unit,
                'dimensions_json' => $normalized->dimensions,
                'period_start' => $normalized->periodStart,
                'period_end' => $normalized->periodEnd,
                'observed_at' => $normalized->observedAt,
                'source_payload_hash' => $normalized->sourcePayloadHash,
                'mapper_version' => $normalized->mapperVersion,
            ]);
            $observation->save();

            return ['outcome' => 'updated', 'observation' => $observation->fresh()];
        });
    }

    private function metricValuesEqual(mixed $stored, ?string $incoming): bool
    {
        if ($stored === null && ($incoming === null || $incoming === '')) {
            return true;
        }

        if ($stored === null || $incoming === null || $incoming === '') {
            return false;
        }

        if (! is_numeric($stored) || ! is_numeric($incoming)) {
            return (string) $stored === (string) $incoming;
        }

        return abs((float) $stored - (float) $incoming) < 0.0000001;
    }
}
