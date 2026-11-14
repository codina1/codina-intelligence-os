<?php

namespace App\Normalization\Services;

use App\Identity\Services\AuthorizationService;
use App\Models\ContentEntity;
use App\Models\ContentVersion;
use App\Models\DataSource;
use App\Models\MetricObservation;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InventoryQueryService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly NormalizationQualityService $quality,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $tenantId = $this->resolveTenantId($user, $filters['tenant_id'] ?? null);
        $this->authorization->assertPermission($user, 'inventory.view', $tenantId);

        $entities = ContentEntity::query()->withoutTenantIsolation()->where('tenant_id', $tenantId);
        $metrics = MetricObservation::query()->withoutTenantIsolation()->where('tenant_id', $tenantId);

        $byContentType = (clone $entities)
            ->selectRaw('content_type, COUNT(*) as aggregate')
            ->groupBy('content_type')
            ->pluck('aggregate', 'content_type')
            ->all();

        $byProvider = MetricObservation::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->selectRaw('provider, COUNT(*) as aggregate')
            ->groupBy('provider')
            ->pluck('aggregate', 'provider')
            ->all();

        return [
            'is_simulated' => true,
            'mock_only' => true,
            'tenant_id' => $tenantId,
            'content_entities' => (clone $entities)->count(),
            'metric_observations' => (clone $metrics)->count(),
            'by_content_type' => $byContentType,
            'metrics_by_provider' => $byProvider,
            'source_completeness_note' => 'Current fixtures are minimal; URLs, slugs, SEO fields, and analytics dimensions are null unless present in staging.',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listEntities(User $user, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $tenantId = $this->resolveTenantId($user, $filters['tenant_id'] ?? null);
        $this->authorization->assertPermission($user, 'inventory.view', $tenantId);

        $query = ContentEntity::query()->withoutTenantIsolation()->where('tenant_id', $tenantId);

        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }
        if (! empty($filters['data_source_id'])) {
            $source = $this->findAccessibleSource($user, (string) $filters['data_source_id']);
            $query->where('data_source_id', $source->id);
        }
        if (! empty($filters['normalized_status'])) {
            $query->where('normalized_status', $filters['normalized_status']);
        }

        $sort = (string) ($filters['sort'] ?? 'id');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'title', 'content_type', 'normalized_at', 'last_seen_at', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        return $query->orderBy($sort, $direction)->paginate($perPage);
    }

    public function findEntity(User $user, string $publicId): ContentEntity
    {
        $entity = $this->membershipScopedEntities($user)->where('public_id', $publicId)->first();
        if ($entity === null) {
            throw new NotFoundHttpException('Content entity not found.');
        }

        $this->authorization->assertPermission($user, 'inventory.view', (int) $entity->tenant_id);

        return $entity;
    }

    public function listVersions(User $user, string $publicId, int $perPage = 15): LengthAwarePaginator
    {
        $entity = $this->findEntity($user, $publicId);

        return ContentVersion::query()->withoutTenantIsolation()
            ->where('tenant_id', $entity->tenant_id)
            ->where('content_entity_id', $entity->id)
            ->orderByDesc('version_number')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listMetrics(User $user, array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $tenantId = $this->resolveTenantId($user, $filters['tenant_id'] ?? null);
        $this->authorization->assertPermission($user, 'inventory.view', $tenantId);

        $query = MetricObservation::query()->withoutTenantIsolation()->where('tenant_id', $tenantId);

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (! empty($filters['metric_key'])) {
            $query->where('metric_key', $filters['metric_key']);
        }
        if (! empty($filters['data_source_id'])) {
            $source = $this->findAccessibleSource($user, (string) $filters['data_source_id']);
            $query->where('data_source_id', $source->id);
        }

        $sort = (string) ($filters['sort'] ?? 'id');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'metric_key', 'metric_value', 'provider', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        return $query->orderBy($sort, $direction)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dataQuality(User $user, array $filters = []): array
    {
        $tenantId = $this->resolveTenantId($user, $filters['tenant_id'] ?? null);
        $this->authorization->assertPermission($user, 'inventory.view', $tenantId);

        return $this->quality->summarizeForTenant($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEntity(ContentEntity $entity): array
    {
        $source = DataSource::query()->withoutTenantIsolation()->find($entity->data_source_id);

        return [
            'id' => $entity->public_id,
            'data_source_id' => $source?->public_id,
            'entity_type' => $entity->entity_type,
            'external_id' => $entity->external_id,
            'content_type' => $entity->content_type,
            'title' => $entity->title,
            'slug' => $entity->slug,
            'canonical_url' => $entity->canonical_url,
            'publication_status' => $entity->publication_status,
            'normalized_status' => $entity->normalized_status,
            'current_version_number' => $entity->current_version_number,
            'normalized_payload_hash' => $entity->normalized_payload_hash,
            'source_updated_at' => optional($entity->source_updated_at)?->toIso8601String(),
            'first_seen_at' => optional($entity->first_seen_at)?->toIso8601String(),
            'last_seen_at' => optional($entity->last_seen_at)?->toIso8601String(),
            'normalized_at' => optional($entity->normalized_at)?->toIso8601String(),
            'is_simulated' => true,
            'mock_only' => true,
            'provenance' => [
                'provider' => $source?->provider,
                'data_source_mode' => $source?->getAttributes()['mode'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeVersion(ContentVersion $version): array
    {
        return [
            'version_number' => $version->version_number,
            'normalized_payload_hash' => $version->normalized_payload_hash,
            'normalized_payload' => $version->normalized_payload_json,
            'mapper_version' => $version->mapper_version,
            'source_ingestion_record_id' => $version->source_ingestion_record_id,
            'created_at' => optional($version->created_at)?->toIso8601String(),
            'is_simulated' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMetric(MetricObservation $observation): array
    {
        $source = DataSource::query()->withoutTenantIsolation()->find($observation->data_source_id);

        return [
            'id' => $observation->public_id,
            'data_source_id' => $source?->public_id,
            'provider' => $observation->provider,
            'metric_key' => $observation->metric_key,
            'metric_value' => $observation->metric_value,
            'unit' => $observation->unit,
            'dimensions' => $observation->dimensions_json,
            'period_start' => optional($observation->period_start)?->toIso8601String(),
            'period_end' => optional($observation->period_end)?->toIso8601String(),
            'observed_at' => optional($observation->observed_at)?->toIso8601String(),
            'mapper_version' => $observation->mapper_version,
            'is_simulated' => true,
            'mock_only' => true,
            'linked_content_entity_id' => null,
            'link_note' => 'Cross-source linking requires explicit deterministic evidence; none in Phase 02C fixtures.',
        ];
    }

    private function resolveTenantId(User $user, mixed $requested): int
    {
        if ($user->isPlatformAdmin()) {
            if ($requested === null || $requested === '') {
                throw new AccessDeniedHttpException('tenant_id is required for platform admin inventory queries.');
            }

            return (int) $requested;
        }

        $ids = $user->accessibleTenantIds();
        if ($requested !== null && $requested !== '') {
            $tenantId = (int) $requested;
            if (! in_array($tenantId, $ids, true)) {
                throw new AccessDeniedHttpException('Cross-tenant access denied.');
            }

            return $tenantId;
        }

        if (count($ids) === 1) {
            return (int) $ids[0];
        }

        if ($ids === []) {
            throw new AccessDeniedHttpException('No tenant membership.');
        }

        throw new AccessDeniedHttpException('tenant_id is required when multiple tenants are accessible.');
    }

    private function membershipScopedEntities(User $user): Builder
    {
        $query = ContentEntity::query()->withoutTenantIsolation();

        if ($user->isPlatformAdmin()) {
            return $query;
        }

        $ids = $user->accessibleTenantIds();

        return $query->whereIn('tenant_id', $ids === [] ? [-1] : $ids);
    }

    private function findAccessibleSource(User $user, string $publicId): DataSource
    {
        $query = DataSource::query()->withoutTenantIsolation()->where('public_id', $publicId);

        if (! $user->isPlatformAdmin()) {
            $ids = $user->accessibleTenantIds();
            $query->whereIn('tenant_id', $ids === [] ? [-1] : $ids);
        }

        $source = $query->first();
        if ($source === null) {
            throw new NotFoundHttpException('Data source not found.');
        }

        return $source;
    }
}
