<?php

namespace App\Normalization\Services;

use App\Audit\Services\AuditLogger;
use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Identity\Services\AuthorizationService;
use App\Models\DataSource;
use App\Models\NormalizationRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Jobs\NormalizationRunJob;
use App\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly NormalizationLeaseManager $leases,
    ) {}

    public function start(User $user, string $dataSourcePublicId): NormalizationRun
    {
        $source = $this->findSourceForNormalize($user, $dataSourcePublicId);
        $this->assertSourceEligibleForNormalize($source);

        if (config('codina.allow_external_http') === true) {
            throw new DomainException(NormalizationErrorCode::ForbiddenMode->value);
        }

        $this->assertProviderHasMapper((string) $source->provider);

        $tenant = $source->tenant()->first() ?? Tenant::query()->findOrFail($source->tenant_id);

        try {
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

                    $this->leases->ensureScopeRow($run);
                    $this->leases->reserveForQueuedRun($run);

                    $this->auditLogger->log('normalization.started', $user, $run, [
                        'data_source_id' => $source->public_id,
                        'provider' => $source->provider,
                    ], (int) $source->tenant_id);

                    return $run->fresh();
                });
            });
        } catch (DomainException $e) {
            if ($e->getMessage() === NormalizationErrorCode::ActiveRunConflict->value) {
                throw new ConflictHttpException('An active normalization run already owns this data source.');
            }

            throw $e;
        }

        NormalizationRunJob::dispatch($run->id, (int) $run->tenant_id, (int) $user->id);

        return $run->fresh() ?? $run;
    }

    public function executeQueuedRun(int $runId, int $tenantId, int $userId): void
    {
        $user = User::query()->find($userId);
        $tenant = Tenant::query()->find($tenantId);

        if ($user === null || $tenant === null) {
            $this->terminateUnauthorized($runId, $tenantId, null, 'missing_user_or_tenant');

            return;
        }

        $run = NormalizationRun::query()->withoutTenantIsolation()
            ->where('id', $runId)
            ->first();

        if ($run === null || (int) $run->tenant_id !== (int) $tenantId) {
            $this->auditLogger->log('normalization.unauthorized_execution', $user, null, [
                'reason' => 'run_tenant_mismatch_or_missing',
                'run_id' => $runId,
                'tenant_id' => $tenantId,
            ], $tenantId);

            return;
        }

        try {
            $this->tenantContext->runWithTenant($tenant, function () use ($run, $user, $tenantId) {
                if (! $this->userMayExecuteNormalization($user, $tenantId)) {
                    $this->failUnauthorized($run, $user, 'permission_or_membership_revoked');

                    return;
                }

                $source = DataSource::query()
                    ->where('id', $run->data_source_id)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if ($source === null) {
                    $this->failUnauthorized($run, $user, 'data_source_missing_or_cross_tenant');

                    return;
                }

                try {
                    $this->assertSourceEligibleForNormalize($source);
                } catch (UnprocessableEntityHttpException $e) {
                    $code = str_contains($e->getMessage(), 'Disabled')
                        ? NormalizationErrorCode::SourceDisabled
                        : NormalizationErrorCode::ForbiddenMode;
                    $this->failTerminal($run, $user, $code, 'source_not_eligible');

                    return;
                }

                if (config('codina.allow_external_http') === true) {
                    $this->failTerminal($run, $user, NormalizationErrorCode::ForbiddenMode, 'external_http_enabled');

                    return;
                }

                $lease = $this->leases->acquire($run, $run->lease_owner);
                $this->runner->run($run->fresh(), $lease);
            });
        } catch (DomainException $e) {
            if ($e->getMessage() === NormalizationErrorCode::ActiveRunConflict->value
                || $e->getMessage() === NormalizationErrorCode::StaleLease->value) {
                $this->failTerminal(
                    $run->fresh() ?? $run,
                    $user,
                    NormalizationErrorCode::from($e->getMessage()),
                    'lease_conflict'
                );

                return;
            }

            throw $e;
        } finally {
            $this->tenantContext->clear();
        }
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

    private function userMayExecuteNormalization(User $user, int $tenantId): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            return false;
        }

        return $user->hasPermissionInTenant('normalize.manage', $tenantId)
            || $user->hasPermissionInTenant('sync.manage', $tenantId)
            || $user->hasPermissionInTenant('connectors.manage', $tenantId);
    }

    private function assertSourceEligibleForNormalize(DataSource $source): void
    {
        $modeValue = (string) ($source->getAttributes()['mode'] ?? '');
        if ($modeValue !== DataSourceMode::Mock->value) {
            throw new UnprocessableEntityHttpException('Only mock data sources may be normalized.');
        }

        if ($source->status === DataSourceStatus::Disabled) {
            throw new UnprocessableEntityHttpException('Disabled data sources cannot be normalized.');
        }
    }

    private function assertProviderHasMapper(string $provider): void
    {
        foreach ($this->registry->all() as $normalizer) {
            if ($normalizer->provider() === $provider) {
                return;
            }
        }

        throw new UnprocessableEntityHttpException('No normalizer registered for provider.');
    }

    private function failUnauthorized(NormalizationRun $run, User $user, string $reason): void
    {
        $this->failTerminal($run, $user, NormalizationErrorCode::UnauthorizedExecution, $reason);
        $this->leases->release($run);
    }

    private function failTerminal(
        NormalizationRun $run,
        ?User $user,
        NormalizationErrorCode $code,
        string $reason,
    ): void {
        if ($run->status === NormalizationRunStatus::Completed
            || $run->status === NormalizationRunStatus::CompletedWithErrors) {
            return;
        }

        $run->forceFill([
            'status' => NormalizationRunStatus::Failed,
            'finished_at' => CarbonImmutable::now(),
            'last_error_code' => $code->value,
        ])->save();

        $this->auditLogger->log('normalization.execution_denied', $user, $run, [
            'reason' => $reason,
            'error_code' => $code->value,
        ], (int) $run->tenant_id);

        $this->leases->release($run);
    }

    private function terminateUnauthorized(int $runId, int $tenantId, ?User $user, string $reason): void
    {
        $run = NormalizationRun::query()->withoutTenantIsolation()->find($runId);
        if ($run !== null) {
            $this->failTerminal($run, $user, NormalizationErrorCode::UnauthorizedExecution, $reason);
        } else {
            $this->auditLogger->log('normalization.unauthorized_execution', $user, null, [
                'reason' => $reason,
                'run_id' => $runId,
                'tenant_id' => $tenantId,
            ], $tenantId);
        }
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
}
