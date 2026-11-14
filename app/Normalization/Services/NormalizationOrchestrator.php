<?php

namespace App\Normalization\Services;

use App\Audit\Services\AuditLogger;
use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Identity\Services\AuthorizationService;
use App\Models\DataSource;
use App\Models\NormalizationRun;
use App\Models\Tenant;
use App\Models\User;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Jobs\NormalizationRunJob;
use App\Tenancy\Services\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class NormalizationOrchestrator
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly NormalizationRunner $runner,
        private readonly NormalizerRegistry $registry,
    ) {}

    public function start(User $user, string $dataSourcePublicId): NormalizationRun
    {
        $source = $this->findSourceForNormalize($user, $dataSourcePublicId);

        $modeValue = (string) ($source->getAttributes()['mode'] ?? '');
        if ($modeValue !== DataSourceMode::Mock->value) {
            throw new UnprocessableEntityHttpException('Only mock data sources may be normalized.');
        }

        if ($source->status === DataSourceStatus::Disabled) {
            throw new UnprocessableEntityHttpException('Disabled data sources cannot be normalized.');
        }

        if (config('codina.allow_external_http') === true) {
            throw new DomainException(NormalizationErrorCode::ForbiddenMode->value);
        }

        if (! $this->registry->supports((string) $source->provider, $this->defaultEntityForProvider((string) $source->provider))) {
            // Still allow start — runner rejects unsupported entity rows individually.
            // Provider with no mappers at all is invalid.
            $hasAny = false;
            foreach ($this->registry->all() as $normalizer) {
                if ($normalizer->provider() === $source->provider) {
                    $hasAny = true;
                    break;
                }
            }
            if (! $hasAny) {
                throw new UnprocessableEntityHttpException('No normalizer registered for provider.');
            }
        }

        $tenant = $source->tenant()->first() ?? Tenant::query()->findOrFail($source->tenant_id);

        $run = $this->tenantContext->runWithTenant($tenant, function () use ($user, $source) {
            return DB::transaction(function () use ($user, $source) {
                $run = NormalizationRun::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'tenant_id' => $source->tenant_id,
                    'data_source_id' => $source->id,
                    'status' => NormalizationRunStatus::Queued,
                    'mapper_version' => (string) config('normalization.default_mapper_bundle_version', '1.0.0'),
                    'requested_by' => $user->id,
                    'is_simulated' => true,
                ]);

                $this->auditLogger->log('normalization.started', $user, $run, [
                    'data_source_id' => $source->public_id,
                    'provider' => $source->provider,
                ], (int) $source->tenant_id);

                return $run;
            });
        });

        NormalizationRunJob::dispatch($run->id, (int) $run->tenant_id, (int) $user->id);

        // Sync queue connection executes immediately; refresh for API response.
        return $run->fresh() ?? $run;
    }

    public function executeQueuedRun(int $runId, int $tenantId, int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        if (! $user->isPlatformAdmin() && ! in_array($tenantId, $user->accessibleTenantIds(), true)) {
            throw new RuntimeException('Normalization execute rejected: cross-tenant access.');
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->tenantContext->runWithTenant($tenant, function () use ($runId, $tenantId) {
            $run = NormalizationRun::query()
                ->where('id', $runId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($run === null) {
                throw new NotFoundHttpException('Normalization run not found.');
            }

            $source = DataSource::query()
                ->where('id', $run->data_source_id)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($source === null) {
                throw new NotFoundHttpException('Data source not found.');
            }

            $modeValue = (string) ($source->getAttributes()['mode'] ?? '');
            if ($modeValue !== DataSourceMode::Mock->value) {
                throw new DomainException(NormalizationErrorCode::ForbiddenMode->value);
            }

            if (config('codina.allow_external_http') === true) {
                throw new DomainException(NormalizationErrorCode::ForbiddenMode->value);
            }

            $this->runner->run($run);
        });
    }

    public function findRunForView(User $user, string $publicId): NormalizationRun
    {
        $query = NormalizationRun::query()->withoutTenantIsolation()->where('public_id', $publicId);

        if (! $user->isPlatformAdmin()) {
            $ids = $user->accessibleTenantIds();
            $query->whereIn('tenant_id', $ids === [] ? [-1] : $ids);
        }

        $run = $query->first();
        if ($run === null) {
            throw new NotFoundHttpException('Normalization run not found.');
        }

        $this->authorization->assertPermission($user, 'inventory.view', (int) $run->tenant_id);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(NormalizationRun $run): array
    {
        $source = DataSource::query()->withoutTenantIsolation()->find($run->data_source_id);

        return [
            'id' => $run->public_id,
            'data_source_id' => $source?->public_id,
            'status' => $run->status->value,
            'mapper_version' => $run->mapper_version,
            'records_scanned' => $run->records_scanned,
            'entities_created' => $run->entities_created,
            'entities_updated' => $run->entities_updated,
            'entities_unchanged' => $run->entities_unchanged,
            'versions_created' => $run->versions_created,
            'metrics_created' => $run->metrics_created,
            'metrics_updated' => $run->metrics_updated,
            'records_rejected' => $run->records_rejected,
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'finished_at' => optional($run->finished_at)?->toIso8601String(),
            'last_error_code' => $run->last_error_code,
            'is_simulated' => (bool) $run->is_simulated,
            'mock_only' => true,
        ];
    }

    private function findSourceForNormalize(User $user, string $publicId): DataSource
    {
        $source = $this->findSource($user, $publicId, 'connectors.view');
        $this->assertNormalizeManage($user, (int) $source->tenant_id);

        return $source;
    }

    private function assertNormalizeManage(User $user, int $tenantId): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($user->hasPermissionInTenant('normalize.manage', $tenantId)
            || $user->hasPermissionInTenant('sync.manage', $tenantId)
            || $user->hasPermissionInTenant('connectors.manage', $tenantId)) {
            return;
        }

        throw new AccessDeniedHttpException('This action is unauthorized.');
    }

    private function findSource(User $user, string $publicId, string $permission): DataSource
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass($user, 'normalization.source.'.$permission, function () use ($publicId, $user, $permission) {
                $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $publicId)->first();
                if ($source === null) {
                    throw new NotFoundHttpException('Data source not found.');
                }
                $this->authorization->assertPermission($user, $permission, (int) $source->tenant_id);

                return $source;
            });
        }

        $ids = $user->accessibleTenantIds();
        $source = DataSource::query()->withoutTenantIsolation()
            ->whereIn('tenant_id', $ids)
            ->where('public_id', $publicId)
            ->first();

        if ($source === null) {
            throw new NotFoundHttpException('Data source not found.');
        }

        $this->authorization->assertPermission($user, $permission, (int) $source->tenant_id);

        return $source;
    }

    private function defaultEntityForProvider(string $provider): string
    {
        return match ($provider) {
            'wordpress' => 'posts',
            'woocommerce' => 'products',
            'tutor_lms_pro' => 'courses',
            'google_search_console' => 'search_analytics',
            'google_analytics_4' => 'reports',
            default => '',
        };
    }
}
