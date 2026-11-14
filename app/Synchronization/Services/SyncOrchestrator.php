<?php

namespace App\Synchronization\Services;

use App\Audit\Services\AuditLogger;
use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Connectors\Registry\ProviderRegistry;
use App\Identity\Services\AuthorizationService;
use App\Models\DataSource;
use App\Models\SyncActiveScope;
use App\Models\SyncAttempt;
use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\User;
use App\Synchronization\Enums\SyncErrorCode;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Jobs\ExecuteSyncRunJob;
use App\Synchronization\Validators\SyncOptionsValidator;
use App\Synchronization\Validators\SyncRecordValidator;
use App\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SyncOrchestrator
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ProviderRegistry $providers,
        private readonly SyncRecordValidator $validator,
        private readonly SyncOptionsValidator $optionsValidator,
        private readonly SyncLeaseManager $leases,
        private readonly CheckpointManager $checkpoints,
        private readonly SyncRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function start(User $user, string $dataSourcePublicId, array $input): SyncRun
    {
        $source = $this->findSourceForManage($user, $dataSourcePublicId);

        $syncType = SyncType::tryFrom((string) ($input['sync_type'] ?? ''));
        if ($syncType === null) {
            throw ValidationException::withMessages(['sync_type' => 'sync_type must be full or incremental.']);
        }

        $entityType = (string) ($input['entity_type'] ?? '');
        if (! $this->validator->supportsEntity($source->provider, $entityType)) {
            throw ValidationException::withMessages(['entity_type' => 'Unsupported entity type for provider.']);
        }

        $scopeKey = (string) ($input['scope_key'] ?? 'default');
        if ($scopeKey === '' || strlen($scopeKey) > 128) {
            throw ValidationException::withMessages(['scope_key' => 'Invalid scope_key.']);
        }

        $modeValue = (string) ($source->getAttributes()['mode'] ?? '');
        if ($modeValue !== DataSourceMode::Mock->value) {
            throw new UnprocessableEntityHttpException('Only mock data sources may be synchronized.');
        }

        if ($source->status === DataSourceStatus::Disabled) {
            throw new UnprocessableEntityHttpException('Disabled data sources cannot sync.');
        }

        if (config('codina.allow_external_http') === true) {
            throw new DomainException(SyncErrorCode::ForbiddenMode->value);
        }

        $defaults = [
            'scenario' => $source->settings['fixture_dataset'] ?? 'success',
            'per_page' => $source->settings['pagination_size'] ?? config('sync.default_page_size'),
            'error_scenario' => $source->settings['simulated_error_scenario'] ?? 'none',
            'incremental_dataset' => $source->settings['incremental_dataset'] ?? 'incremental',
            'simulated' => true,
        ];

        $publicOptions = (isset($input['options']) && is_array($input['options'])) ? $input['options'] : [];
        $options = $this->optionsValidator->validatePublicOptions($publicOptions, $defaults);

        $tenant = $source->tenant()->first() ?? Tenant::query()->findOrFail($source->tenant_id);

        $run = $this->tenantContext->runWithTenant(
            $tenant,
            function () use ($user, $source, $syncType, $entityType, $scopeKey, $options) {
                return DB::transaction(function () use ($user, $source, $syncType, $entityType, $scopeKey, $options) {
                    $this->leases->ensureScopeRow(new SyncRun([
                        'tenant_id' => $source->tenant_id,
                        'data_source_id' => $source->id,
                        'entity_type' => $entityType,
                        'scope_key' => $scopeKey,
                    ]));

                    $scope = SyncActiveScope::query()->withoutTenantIsolation()
                        ->where('data_source_id', $source->id)
                        ->where('entity_type', $entityType)
                        ->where('scope_key', $scopeKey)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $now = CarbonImmutable::now();
                    $busy = $scope->sync_run_id !== null
                        && $scope->lease_expires_at !== null
                        && $scope->lease_expires_at->gt($now);

                    if ($busy) {
                        $active = SyncRun::query()->withoutTenantIsolation()->find($scope->sync_run_id);
                        if ($active !== null && $active->status->isActive()) {
                            throw new ConflictHttpException('An active sync already owns this source scope.');
                        }
                    }

                    $run = SyncRun::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'tenant_id' => $source->tenant_id,
                        'data_source_id' => $source->id,
                        'entity_type' => $entityType,
                        'scope_key' => $scopeKey,
                        'sync_type' => $syncType,
                        'status' => SyncRunStatus::Queued,
                        'generation' => 0,
                        'requested_by' => $user->id,
                        'options_json' => $options,
                        'is_simulated' => true,
                        'completeness_status' => 'pending',
                    ]);

                    // Reserve scope immediately so a second queued run cannot race before lease acquire.
                    $this->leases->reserveForQueuedRun($run);

                    $this->checkpoints->getOrCreate($run);

                    $this->auditLogger->log('sync_run.queued', $user, $run, [
                        'data_source_id' => $source->public_id,
                        'sync_type' => $syncType->value,
                        'entity_type' => $entityType,
                        'scope_key' => $scopeKey,
                        'simulated' => true,
                    ], (int) $source->tenant_id);

                    return $run;
                });
            }
        );

        ExecuteSyncRunJob::dispatch($run->id, (int) $source->tenant_id, (int) $user->id);

        return $run->fresh();
    }

    public function retry(User $user, string $runPublicId): SyncRun
    {
        $run = $this->findRunForManage($user, $runPublicId);

        if ($run->status === SyncRunStatus::Cancelled || $run->status === SyncRunStatus::Completed) {
            throw new UnprocessableEntityHttpException('Completed or cancelled runs cannot be retried.');
        }

        if (! in_array($run->status, [SyncRunStatus::Failed, SyncRunStatus::Partial], true)) {
            throw new UnprocessableEntityHttpException('Only failed or partial runs can be retried.');
        }

        $source = DataSource::query()->withoutTenantIsolation()->findOrFail($run->data_source_id);
        if ($source->status === DataSourceStatus::Disabled || $source->mode !== DataSourceMode::Mock) {
            throw new UnprocessableEntityHttpException('Source is not eligible for retry.');
        }

        $tenant = Tenant::query()->findOrFail($source->tenant_id);

        $this->tenantContext->runWithTenant($tenant, function () use ($user, $run, $source) {
            $defaults = [
                'scenario' => $source->settings['fixture_dataset'] ?? (($run->options_json['scenario'] ?? null) ?? 'success'),
                'per_page' => $source->settings['pagination_size'] ?? (($run->options_json['per_page'] ?? null) ?? config('sync.default_page_size')),
                'error_scenario' => $source->settings['simulated_error_scenario'] ?? 'none',
                'incremental_dataset' => $source->settings['incremental_dataset'] ?? 'incremental',
                'simulated' => true,
            ];
            $options = $this->optionsValidator->validatePublicOptions([], $defaults);

            if ($run->status === SyncRunStatus::Failed) {
                if (! $run->status->canTransitionTo(SyncRunStatus::Queued)) {
                    throw new DomainException(SyncErrorCode::InvalidTransition->value);
                }
                $run->forceFill([
                    'status' => SyncRunStatus::Queued,
                    'finished_at' => null,
                    'last_error_code' => null,
                    'last_error_summary' => null,
                    'options_json' => $options,
                ])->save();
            } elseif ($run->status === SyncRunStatus::Partial) {
                // Partial -> running happens inside the runner after lease acquire.
                $run->forceFill([
                    'options_json' => $options,
                    'last_error_code' => null,
                    'last_error_summary' => null,
                ])->save();
            }

            // Re-reserve scope before dispatch so concurrent starts cannot sneak in.
            $this->leases->ensureScopeRow($run);
            $this->leases->reserveForQueuedRun($run->fresh());

            $this->auditLogger->log('sync_run.retry_requested', $user, $run, [
                'simulated' => true,
                'retry_count' => $run->retry_count,
            ], (int) $run->tenant_id);
        });

        ExecuteSyncRunJob::dispatch($run->id, (int) $run->tenant_id, (int) $user->id);

        return $run->fresh();
    }

    public function cancel(User $user, string $runPublicId): SyncRun
    {
        $run = $this->findRunForManage($user, $runPublicId);

        if ($run->status->isTerminal()) {
            throw new UnprocessableEntityHttpException('Run is already terminal.');
        }

        $tenant = Tenant::query()->findOrFail($run->tenant_id);

        $this->tenantContext->runWithTenant(
            $tenant,
            function () use ($user, $run) {
                $run->forceFill([
                    'cancelled_by' => $user->id,
                    'cancelled_at' => now(),
                ])->save();

                $this->leases->requestCancel($run, $user->id);

                // Queued/partial runs (not mid-page) flip to cancelled immediately.
                // Running workers observe cancel_requested_at before the next page commit.
                if (in_array($run->status, [SyncRunStatus::Queued, SyncRunStatus::Partial], true)) {
                    $run->forceFill([
                        'status' => SyncRunStatus::Cancelled,
                        'finished_at' => now(),
                        'completeness_status' => 'cancelled',
                        'last_error_code' => SyncErrorCode::Cancelled->value,
                        'last_error_summary' => 'Cancellation acknowledged.',
                    ])->save();
                }

                $this->auditLogger->log('sync_run.cancel_requested', $user, $run, [
                    'simulated' => true,
                ], (int) $run->tenant_id);
            }
        );

        return $run->fresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listRuns(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->membershipScopedRuns($user, isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null);

        if (! empty($filters['data_source_id'])) {
            $source = $this->findSourceForView($user, (string) $filters['data_source_id']);
            $query->where('data_source_id', $source->id);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', (string) $filters['entity_type']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findRunForView(User $user, string $publicId): SyncRun
    {
        $run = $this->membershipScopedRuns($user)->where('public_id', $publicId)->first();
        if ($run === null) {
            throw new NotFoundHttpException('Sync run not found.');
        }
        $this->authorization->assertPermission($user, 'connectors.view', (int) $run->tenant_id);

        return $run;
    }

    public function listAttempts(User $user, string $runPublicId, int $perPage = 15): LengthAwarePaginator
    {
        $run = $this->findRunForView($user, $runPublicId);

        return SyncAttempt::query()->withoutTenantIsolation()
            ->where('sync_run_id', $run->id)
            ->orderBy('attempt_number')
            ->paginate($perPage);
    }

    public function listCheckpoints(User $user, string $dataSourcePublicId, int $perPage = 15): LengthAwarePaginator
    {
        $source = $this->findSourceForView($user, $dataSourcePublicId);

        return SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncHealth(User $user, string $dataSourcePublicId): array
    {
        $source = $this->findSourceForView($user, $dataSourcePublicId);

        $latest = SyncRun::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->orderByDesc('id')
            ->first();

        $checkpoints = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->get();

        return [
            'data_source_id' => $source->public_id,
            'mode' => $source->mode->value,
            'status' => $source->status->value,
            'simulated' => true,
            'latest_run' => $latest ? $this->serializeRun($latest) : null,
            'checkpoint_count' => $checkpoints->count(),
            'checkpoints' => $checkpoints->map(fn (SyncCheckpoint $c) => $this->serializeCheckpoint($c))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeRun(SyncRun $run): array
    {
        return [
            'id' => $run->public_id,
            'data_source_id' => DataSource::query()->withoutTenantIsolation()->whereKey($run->data_source_id)->value('public_id'),
            'entity_type' => $run->entity_type,
            'scope_key' => $run->scope_key,
            'sync_type' => $run->sync_type->value,
            'status' => $run->status->value,
            'is_simulated' => (bool) $run->is_simulated,
            'rows_received' => $run->rows_received,
            'rows_inserted' => $run->rows_inserted,
            'rows_updated' => $run->rows_updated,
            'rows_unchanged' => $run->rows_unchanged,
            'rows_rejected' => $run->rows_rejected,
            'rows_duplicate_in_batch' => $run->rows_duplicate_in_batch,
            'pages_completed' => $run->pages_completed,
            'completeness_status' => $run->completeness_status,
            'retry_count' => $run->retry_count,
            'last_error_code' => $run->last_error_code,
            'last_error_summary' => $run->last_error_summary,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAttempt(SyncAttempt $attempt): array
    {
        return [
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'finished_at' => $attempt->finished_at?->toIso8601String(),
            'error_code' => $attempt->error_code,
            'error_summary' => $attempt->error_summary,
            'next_retry_at' => $attempt->next_retry_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCheckpoint(SyncCheckpoint $checkpoint): array
    {
        return [
            'entity_type' => $checkpoint->entity_type,
            'scope_key' => $checkpoint->scope_key,
            'last_completed_page' => $checkpoint->last_completed_page,
            'version' => $checkpoint->version,
            'cursor' => $checkpoint->cursor_json,
            'committed_watermark' => $checkpoint->committed_watermark_json,
            'last_success_at' => $checkpoint->last_success_at?->toIso8601String(),
            // Internal fencing/generation/lease fields intentionally omitted from public API.
        ];
    }

    public function executeQueuedRun(int $runId, int $tenantId, int $userId): void
    {
        $run = SyncRun::query()->withoutTenantIsolation()->find($runId);
        if ($run === null) {
            return;
        }

        if ((int) $run->tenant_id !== $tenantId) {
            throw new DomainException(SyncErrorCode::TenantMismatch->value);
        }

        if ($run->status === SyncRunStatus::Cancelled || $run->status === SyncRunStatus::Completed) {
            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            throw new DomainException(SyncErrorCode::Unauthorized->value);
        }

        if (! $user->isPlatformAdmin() && ! in_array($tenantId, $user->accessibleTenantIds(), true)) {
            throw new DomainException(SyncErrorCode::TenantMismatch->value);
        }

        $source = DataSource::query()->withoutTenantIsolation()->find($run->data_source_id);
        if ($source === null || (int) $source->tenant_id !== $tenantId) {
            throw new DomainException(SyncErrorCode::InvalidConfiguration->value);
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        $this->tenantContext->runWithTenant($tenant, function () use ($run) {
            $this->runner->run($run->fresh());
        });
    }

    private function findSourceForView(User $user, string $publicId): DataSource
    {
        return $this->findSource($user, $publicId, 'connectors.view');
    }

    private function findSourceForManage(User $user, string $publicId): DataSource
    {
        $source = $this->findSource($user, $publicId, 'connectors.view');
        $this->assertSyncManage($user, (int) $source->tenant_id);

        return $source;
    }

    private function findRunForManage(User $user, string $publicId): SyncRun
    {
        $run = $this->findRunForView($user, $publicId);
        $this->assertSyncManage($user, (int) $run->tenant_id);

        return $run;
    }

    private function assertSyncManage(User $user, int $tenantId): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($user->hasPermissionInTenant('sync.manage', $tenantId)
            || $user->hasPermissionInTenant('connectors.manage', $tenantId)) {
            return;
        }

        throw new AccessDeniedHttpException('This action is unauthorized.');
    }

    private function findSource(User $user, string $publicId, string $permission): DataSource
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass($user, 'sync.source.'.$permission, function () use ($publicId, $user, $permission) {
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

    private function membershipScopedRuns(User $user, ?int $tenantId = null): Builder
    {
        $query = SyncRun::query()->withoutTenantIsolation();

        if ($user->isPlatformAdmin()) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            return $query;
        }

        $ids = $user->accessibleTenantIds();
        if ($tenantId !== null) {
            if (! in_array($tenantId, $ids, true)) {
                throw new AccessDeniedHttpException('Cross-tenant access denied.');
            }
            $query->where('tenant_id', $tenantId);
        } else {
            $query->whereIn('tenant_id', $ids === [] ? [-1] : $ids);
        }

        return $query;
    }
}
