<?php

namespace App\Normalization\Services;

use App\Models\ContentEntity;
use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\MetricObservation;
use App\Models\NormalizationFailure;
use App\Models\NormalizationRun;
use App\Models\SyncRun;
use App\Synchronization\Enums\SyncRunStatus;
class NormalizationQualityService
{
    public function __construct(
        private readonly NormalizerRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarizeForTenant(int $tenantId): array
    {
        $sources = DataSource::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->get();

        $entityCount = ContentEntity::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->count();
        $metricCount = MetricObservation::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->count();
        $ingestionCount = IngestionRecord::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->count();

        $byProvider = [];
        $missingFields = [
            'slug' => 0,
            'canonical_url' => 0,
            'source_updated_at' => 0,
        ];

        foreach ($sources as $source) {
            $provider = (string) $source->provider;
            $synced = SyncRun::query()->withoutTenantIsolation()
                ->where('tenant_id', $tenantId)
                ->where('data_source_id', $source->id)
                ->where('status', SyncRunStatus::Completed->value)
                ->exists();

            $stagingCount = IngestionRecord::query()->withoutTenantIsolation()
                ->where('tenant_id', $tenantId)
                ->where('data_source_id', $source->id)
                ->count();

            $normalizedEntities = ContentEntity::query()->withoutTenantIsolation()
                ->where('tenant_id', $tenantId)
                ->where('data_source_id', $source->id)
                ->count();
            $normalizedMetrics = MetricObservation::query()->withoutTenantIsolation()
                ->where('tenant_id', $tenantId)
                ->where('data_source_id', $source->id)
                ->count();

            $supported = $this->registry->all();
            $supportedForProvider = array_values(array_filter(
                $supported,
                static fn ($n) => $n->provider() === $provider
            ));

            $byProvider[$provider] = [
                'data_source_id' => $source->public_id,
                'synced' => $synced,
                'staging_records' => $stagingCount,
                'normalized_entities' => $normalizedEntities,
                'normalized_metrics' => $normalizedMetrics,
                'mapper_supported' => $supportedForProvider !== [],
                'completeness' => [
                    'note' => 'Fixture fields are minimal; absent SEO/URL/price/analytics dimensions stay null.',
                    'null_percentage' => $this->nullPercentage($stagingCount, $normalizedEntities + $normalizedMetrics),
                ],
            ];
        }

        $entities = ContentEntity::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->get(['slug', 'canonical_url', 'source_updated_at']);

        foreach ($entities as $entity) {
            if ($entity->slug === null) {
                $missingFields['slug']++;
            }
            if ($entity->canonical_url === null) {
                $missingFields['canonical_url']++;
            }
            if ($entity->source_updated_at === null) {
                $missingFields['source_updated_at']++;
            }
        }

        $latestFailures = NormalizationFailure::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['error_code', 'safe_diagnostics_json', 'normalization_run_id', 'created_at']);

        $latestRun = NormalizationRun::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        return [
            'is_simulated' => true,
            'mock_only' => true,
            'totals' => [
                'data_sources' => $sources->count(),
                'staging_records' => $ingestionCount,
                'content_entities' => $entityCount,
                'metric_observations' => $metricCount,
            ],
            'missing_optional_fields' => $missingFields,
            'by_provider' => $byProvider,
            'latest_normalization_run' => $latestRun ? [
                'id' => $latestRun->public_id,
                'status' => $latestRun->status->value,
                'records_rejected' => $latestRun->records_rejected,
            ] : null,
            'recent_failures' => $latestFailures->map(static fn (NormalizationFailure $f) => [
                'error_code' => $f->error_code,
                'diagnostics' => $f->safe_diagnostics_json,
                'created_at' => optional($f->created_at)?->toIso8601String(),
            ])->all(),
            'distinctions' => [
                'missing' => 'Field absent from fixture/staging payload — stored as null.',
                'unsupported' => 'Provider/entity has no registered mapper.',
                'failure' => 'Mapper rejected a record; see normalization_failures.',
                'not_synced' => 'No completed SyncRun for the data source yet.',
            ],
        ];
    }

    private function nullPercentage(int $denominator, int $numerator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        // Not a fabricated completeness score — only report mapped/staging ratio when defined.
        return round(($numerator / $denominator) * 100, 2);
    }
}
