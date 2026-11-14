<?php

namespace App\Connectors\Services;

use App\Audit\Services\AuditLogger;
use App\Connectors\Contracts\SecretStoreInterface;
use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Connectors\Registry\ConnectorRegistry;
use App\Connectors\Registry\ProviderRegistry;
use App\Connectors\Validation\ProviderSettingsValidator;
use App\Identity\Services\AuthorizationService;
use App\Models\CredentialReference;
use App\Models\DataSource;
use App\Models\DataSourceCapability;
use App\Models\DataSourceConfigurationVersion;
use App\Models\DataSourceHealthCheck;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DataSourceService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ProviderRegistry $providers,
        private readonly ConnectorRegistry $connectors,
        private readonly ProviderSettingsValidator $settingsValidator,
        private readonly DataSourceLifecycle $lifecycle,
        private readonly SecretStoreInterface $secretStore,
    ) {}

    public function listForUser(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->membershipScopedQuery($user, isset($filters['tenant_id']) ? (int) $filters['tenant_id'] : null);

        if (! empty($filters['site'])) {
            $query->where('site_id', (int) $filters['site']);
        }
        if (! empty($filters['provider'])) {
            $query->where('provider', (string) $filters['provider']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['mode'])) {
            $query->where('mode', (string) $filters['mode']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function findForUser(User $user, string $publicId): DataSource
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass(
                $user,
                'data_sources.find.platform',
                function () use ($publicId, $user) {
                    $source = DataSource::query()
                        ->withoutTenantIsolation()
                        ->with($this->safeRelationLoads())
                        ->where('public_id', $publicId)
                        ->first();

                    if ($source === null) {
                        throw new NotFoundHttpException('Data source not found.');
                    }

                    $this->authorization->assertPermission($user, 'connectors.view', (int) $source->tenant_id);

                    return $source;
                }
            );
        }

        $source = $this->membershipScopedQuery($user)
            ->with($this->safeRelationLoads())
            ->where('public_id', $publicId)
            ->first();

        if ($source === null) {
            throw new NotFoundHttpException('Data source not found.');
        }

        $this->authorization->assertPermission($user, 'connectors.view', (int) $source->tenant_id);

        return $source;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): DataSource
    {
        $tenantId = (int) $data['tenant_id'];
        $this->authorization->assertTenantAccess($user, $tenantId);
        $this->authorization->assertPermission($user, 'connectors.manage', $tenantId);

        $provider = (string) $data['provider'];
        if (! $this->providers->has($provider)) {
            throw ValidationException::withMessages(['provider' => 'Unknown provider.']);
        }

        $mode = DataSourceMode::tryFrom((string) ($data['mode'] ?? 'mock'));
        if ($mode !== DataSourceMode::Mock) {
            throw ValidationException::withMessages(['mode' => 'Only mock mode is allowed in Phase 02A.']);
        }

        [$settings, $externalResourceId] = $this->settingsValidator->validateWithExternalResourceId(
            $provider,
            $data['settings'] ?? [],
            $data['external_resource_id'] ?? null,
        );
        $siteId = array_key_exists('site_id', $data) && $data['site_id'] !== null
            ? (int) $data['site_id']
            : null;

        if ($siteId !== null) {
            $this->assertSiteBelongsToTenant($siteId, $tenantId);
        }

        $authMethod = $data['auth_method'] ?? 'none';
        $definition = $this->providers->get($provider);
        if (! in_array($authMethod, $definition->authMethods, true)) {
            throw ValidationException::withMessages(['auth_method' => 'Unsupported auth method for provider.']);
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        try {
            $source = $this->tenantContext->runWithTenant($tenant, function () use (
                $user,
                $tenantId,
                $siteId,
                $provider,
                $data,
                $settings,
                $externalResourceId,
                $authMethod,
                $definition
            ) {
                return DB::transaction(function () use (
                    $user,
                    $tenantId,
                    $siteId,
                    $provider,
                    $data,
                    $settings,
                    $externalResourceId,
                    $authMethod,
                    $definition
                ) {
                    $source = DataSource::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'site_id' => $siteId,
                        'provider' => $provider,
                        'display_name' => $data['display_name'],
                        'external_resource_id' => $externalResourceId,
                        'mode' => DataSourceMode::Mock,
                        'status' => DataSourceStatus::Configured,
                        'auth_method' => $authMethod,
                        'settings' => $settings,
                    ]);

                    foreach ($definition->capabilities as $capability) {
                        DataSourceCapability::query()->create([
                            'tenant_id' => $tenantId,
                            'data_source_id' => $source->id,
                            'capability_key' => $capability,
                            'availability_status' => 'available',
                            'metadata' => ['source' => 'provider_catalog', 'mode' => 'mock'],
                        ]);
                    }

                    $reference = $this->secretStore->putMockReference((string) $tenantId, 'mock:placeholder:'.$provider);
                    CredentialReference::query()->create([
                        'tenant_id' => $tenantId,
                        'data_source_id' => $source->id,
                        'credential_type' => $authMethod === 'none' ? 'none' : 'mock_metadata',
                        'secret_reference' => $reference,
                        'status' => 'mock',
                        'expires_at' => null,
                    ]);

                    $this->recordConfigurationVersion($source, $user, $settings, 'initial_configuration');

                    return $source->fresh($this->safeRelationLoads());
                });
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new ConflictHttpException('A data source for this provider and site scope already exists.');
            }
            throw $e;
        }

        $this->auditLogger->log('data_source.created', $user, $source, [
            'provider' => $source->provider,
            'status' => $source->status->value,
            'mode' => $source->mode->value,
            'public_id' => $source->public_id,
        ], $tenantId);

        return $source;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $publicId, array $data): DataSource
    {
        $source = $this->findForUser($user, $publicId);
        $this->authorization->assertPermission($user, 'connectors.manage', (int) $source->tenant_id);

        if (isset($data['tenant_id']) && (int) $data['tenant_id'] !== (int) $source->tenant_id) {
            throw new AccessDeniedHttpException('Cannot move a data source across tenants.');
        }

        if (isset($data['provider']) && (string) $data['provider'] !== $source->provider) {
            throw ValidationException::withMessages(['provider' => 'Provider cannot be changed after creation.']);
        }

        if (isset($data['mode']) && (string) $data['mode'] !== DataSourceMode::Mock->value) {
            throw ValidationException::withMessages(['mode' => 'Only mock mode is allowed in Phase 02A.']);
        }

        if (array_key_exists('site_id', $data)) {
            $siteId = $data['site_id'] !== null ? (int) $data['site_id'] : null;
            if ($siteId !== null) {
                $this->assertSiteBelongsToTenant($siteId, (int) $source->tenant_id);
            }
            $source->site_id = $siteId;
        }

        if (isset($data['display_name'])) {
            $source->display_name = (string) $data['display_name'];
        }

        if (isset($data['auth_method'])) {
            $definition = $this->providers->get($source->provider);
            if (! in_array((string) $data['auth_method'], $definition->authMethods, true)) {
                throw ValidationException::withMessages(['auth_method' => 'Unsupported auth method for provider.']);
            }
            $source->auth_method = (string) $data['auth_method'];
        }

        if (isset($data['settings']) || array_key_exists('external_resource_id', $data)) {
            $incomingSettings = $data['settings'] ?? ($source->settings ?? []);
            [$settings, $externalResourceId] = $this->settingsValidator->validateWithExternalResourceId(
                $source->provider,
                is_array($incomingSettings) ? $incomingSettings : [],
                array_key_exists('external_resource_id', $data)
                    ? $data['external_resource_id']
                    : ($incomingSettings['mock_external_resource_id'] ?? null),
            );
            $source->settings = $settings;
            $source->external_resource_id = $externalResourceId;
        }

        if (isset($data['status'])) {
            $target = DataSourceStatus::tryFrom((string) $data['status']);
            if ($target === null) {
                throw ValidationException::withMessages(['status' => 'Invalid status.']);
            }
            try {
                $this->lifecycle->assertPublicTransition($source->status, $target);
            } catch (DomainException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
            if ($source->status === DataSourceStatus::Disabled && $target === DataSourceStatus::Configured) {
                // Re-enable requires valid configuration; does not imply connectivity.
                if ($source->settings === null || $source->settings === []) {
                    throw new UnprocessableEntityHttpException('Cannot re-enable a data source without valid configuration.');
                }
            }
            $source->status = $target;
        } elseif ($source->status === DataSourceStatus::Draft) {
            $this->lifecycle->assertPublicTransition($source->status, DataSourceStatus::Configured);
            $source->status = DataSourceStatus::Configured;
        }

        $source = $this->tenantContext->runWithTenant($source->tenant, function () use ($source, $user, $data) {
            return DB::transaction(function () use ($source, $user, $data) {
                $source->save();
                if (isset($data['settings']) || array_key_exists('external_resource_id', $data)) {
                    $this->recordConfigurationVersion(
                        $source,
                        $user,
                        $source->settings ?? [],
                        $data['change_reason'] ?? 'settings_update'
                    );
                }

                return $source->fresh($this->safeRelationLoads());
            });
        });

        $this->auditLogger->log('data_source.updated', $user, $source, [
            'changes' => array_keys($data),
            'status' => $source->status->value,
        ], (int) $source->tenant_id);

        return $source;
    }

    public function disable(User $user, string $publicId): DataSource
    {
        $source = $this->findForUser($user, $publicId);
        $this->authorization->assertPermission($user, 'connectors.manage', (int) $source->tenant_id);

        try {
            $this->lifecycle->assertTransition($source->status, DataSourceStatus::Disabled);
        } catch (DomainException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        $source = $this->tenantContext->runWithTenant($source->tenant, function () use ($source) {
            $source->status = DataSourceStatus::Disabled;
            $source->save();

            return $source->fresh($this->safeRelationLoads());
        });

        $this->auditLogger->log('data_source.disabled', $user, $source, [
            'public_id' => $source->public_id,
        ], (int) $source->tenant_id);

        return $source;
    }

    /**
     * @return array{source: DataSource, check: DataSourceHealthCheck, result: array<string, mixed>}
     */
    public function testConnection(User $user, string $publicId, ?string $scenario = null): array
    {
        $source = $this->findForUser($user, $publicId);
        $this->authorization->assertPermission($user, 'connectors.manage', (int) $source->tenant_id);

        try {
            $this->lifecycle->assertExecutable($source->status);
        } catch (DomainException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        if ($source->mode !== DataSourceMode::Mock) {
            throw new UnprocessableEntityHttpException('Only mock mode connection tests are available in Phase 02A.');
        }

        if (config('codina.allow_external_http') === true) {
            throw new DomainException('External HTTP must remain disabled for Phase 02A connector tests.');
        }

        $scenario = $scenario
            ?? (string) (($source->settings['simulated_error_scenario'] ?? 'none'));

        if ($scenario === 'none' || $scenario === '') {
            $scenario = 'success';
        }

        $connector = $this->connectors->get($source->provider);
        $started = hrtime(true);

        if ($scenario === 'success') {
            $raw = $connector->testConnection();
            $ok = (bool) ($raw['ok'] ?? false);
            $errorCode = null;
            $message = (string) ($raw['message'] ?? 'Mock connection succeeded.');
            $simulatedLatency = (int) ($raw['latency_ms'] ?? 1);
        } elseif ($scenario === 'empty') {
            $raw = $connector->fetch(['scenario' => 'empty']);
            $ok = true;
            $errorCode = null;
            $message = 'Mock connection returned an empty dataset.';
            $simulatedLatency = 1;
            $raw = ['ok' => true, 'empty' => true, 'meta' => $raw['meta'] ?? []];
        } else {
            $raw = $connector->simulateError($scenario);
            $ok = false;
            $errorCode = (string) ($raw['error']['code'] ?? $scenario);
            $message = (string) ($raw['error']['message'] ?? 'Mock connection failed.');
            $simulatedLatency = 1;
        }

        $durationMs = (int) max(1, (int) (($started ? (hrtime(true) - $started) : 1_000_000) / 1_000_000));

        $persisted = $this->tenantContext->runWithTenant($source->tenant, function () use (
            $source,
            $ok,
            $errorCode,
            $message,
            $scenario,
            $durationMs,
            $simulatedLatency,
            $raw,
        ) {
            return DB::transaction(function () use (
                $source,
                $ok,
                $errorCode,
                $message,
                $scenario,
                $durationMs,
                $simulatedLatency,
                $raw,
            ) {
                $check = DataSourceHealthCheck::query()->create([
                    'tenant_id' => $source->tenant_id,
                    'data_source_id' => $source->id,
                    'check_type' => 'mock_connection_test',
                    'status' => $ok ? 'healthy' : 'error',
                    'duration_ms' => $durationMs,
                    'error_code' => $errorCode,
                    'result_metadata' => [
                        'message' => $message,
                        'scenario' => $scenario,
                        'simulated_latency_ms' => $simulatedLatency,
                        'latency_is_simulated' => true,
                        'external_http' => false,
                        'mode' => 'mock',
                        'verified_real_connection' => false,
                        'raw' => $this->sanitizeResult($raw),
                    ],
                    'checked_at' => now(),
                ]);

                $previousSuccess = $source->last_success_at;
                $source->last_tested_at = now();
                if ($ok) {
                    $source->last_success_at = now();
                    $this->lifecycle->assertTransition($source->status, DataSourceStatus::Healthy);
                    $source->status = DataSourceStatus::Healthy;
                } else {
                    // Preserve last successful timestamp independently from latest failure.
                    $source->last_success_at = $previousSuccess;
                    $this->lifecycle->assertTransition($source->status, DataSourceStatus::Error);
                    $source->status = DataSourceStatus::Error;
                }
                $source->save();

                return [
                    'source' => $source->fresh($this->safeRelationLoads()),
                    'check' => $check,
                ];
            });
        });

        $this->auditLogger->log('data_source.tested', $user, $persisted['source'], [
            'scenario' => $scenario,
            'result' => $ok ? 'healthy' : 'error',
            'error_code' => $errorCode,
            'verified_real_connection' => false,
        ], (int) $persisted['source']->tenant_id);

        return [
            'source' => $persisted['source'],
            'check' => $persisted['check'],
            'result' => [
                'ok' => $ok,
                'status' => $ok ? 'healthy' : 'error',
                'message' => $message,
                'scenario' => $scenario,
                'duration_ms' => $durationMs,
                'simulated_latency_ms' => $simulatedLatency,
                'latency_is_simulated' => true,
                'verified_real_connection' => false,
                'error_code' => $errorCode,
            ],
        ];
    }

    public function healthHistory(User $user, string $publicId, int $perPage = 15): LengthAwarePaginator
    {
        $source = $this->findForUser($user, $publicId);

        return DataSourceHealthCheck::query()
            ->withoutTenantIsolation()
            ->where('tenant_id', $source->tenant_id)
            ->where('data_source_id', $source->id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capabilities(User $user, string $publicId): array
    {
        $source = $this->findForUser($user, $publicId);

        return $source->capabilities->map(fn (DataSourceCapability $cap) => [
            'capability_key' => $cap->capability_key,
            'availability_status' => $cap->availability_status,
            'metadata' => $cap->metadata ?? [],
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(DataSource $source, User $user, bool $includeCredentialsMeta = true): array
    {
        $payload = [
            'id' => $source->public_id,
            'internal_id' => $source->id,
            'tenant_id' => $source->tenant_id,
            'site_id' => $source->site_id,
            'provider' => $source->provider,
            'display_name' => $source->display_name,
            'external_resource_id' => $source->external_resource_id,
            'mode' => $source->mode->value,
            'status' => $source->status->value,
            'auth_method' => $source->auth_method,
            'settings' => $source->settings ?? [],
            'last_tested_at' => $source->last_tested_at?->toIso8601String(),
            'last_success_at' => $source->last_success_at?->toIso8601String(),
            'capabilities' => $source->relationLoaded('capabilities')
                ? $source->capabilities->map(fn ($c) => [
                    'capability_key' => $c->capability_key,
                    'availability_status' => $c->availability_status,
                ])->all()
                : [],
            'created_at' => $source->created_at?->toIso8601String(),
            'updated_at' => $source->updated_at?->toIso8601String(),
        ];

        if ($includeCredentialsMeta && $source->relationLoaded('credentialReferences')) {
            $canManage = $user->isPlatformAdmin()
                || $user->hasPermissionInTenant('connectors.manage', (int) $source->tenant_id);

            $payload['credentials'] = $canManage
                ? $source->credentialReferences->map(fn (CredentialReference $ref) => $ref->toSafeArray())->all()
                : $source->credentialReferences->map(fn (CredentialReference $ref) => [
                    'credential_type' => $ref->credential_type,
                    'status' => $ref->status,
                    'has_reference' => true,
                ])->all();
        }

        return $payload;
    }

    private function membershipScopedQuery(User $user, ?int $tenantId = null): Builder
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass(
                $user,
                'data_sources.query.platform',
                function () use ($tenantId) {
                    $query = DataSource::query()->withoutTenantIsolation()->with($this->safeRelationLoads(['capabilities']));
                    if ($tenantId !== null) {
                        $query->where('tenant_id', $tenantId);
                    }

                    return $query;
                }
            );
        }

        $accessible = $user->accessibleTenantIds();

        if ($tenantId !== null) {
            $this->authorization->assertTenantAccess($user, $tenantId);
            $this->authorization->assertPermission($user, 'connectors.view', $tenantId);
            $accessible = [$tenantId];
        }

        if ($accessible === []) {
            throw new AccessDeniedHttpException('No accessible tenants.');
        }

        // Ensure at least one tenant grants connectors.view when listing broadly.
        if ($tenantId === null) {
            $allowed = array_values(array_filter(
                $accessible,
                fn (int $id) => $user->hasPermissionInTenant('connectors.view', $id)
            ));
            if ($allowed === []) {
                throw new AccessDeniedHttpException('This action is unauthorized.');
            }
            $accessible = $allowed;
        }

        return DataSource::query()
            ->withoutTenantIsolation()
            ->with($this->safeRelationLoads(['capabilities']))
            ->whereIn('tenant_id', $accessible);
    }

    /**
     * Eager-load tenant-owned relations without requiring ambient TenantContext.
     * Callers must already have authorized the parent row's tenant.
     *
     * @param  list<string>  $relations
     * @return array<string, \Closure>
     */
    private function safeRelationLoads(array $relations = ['capabilities', 'credentialReferences']): array
    {
        $loads = [];
        foreach ($relations as $relation) {
            $loads[$relation] = static function ($query): void {
                $query->withoutTenantIsolation();
            };
        }

        return $loads;
    }

    private function assertSiteBelongsToTenant(int $siteId, int $tenantId): void
    {
        $site = Site::query()
            ->withoutTenantIsolation()
            ->whereKey($siteId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($site === null) {
            // Indistinguishable from missing — do not reveal cross-tenant site existence.
            throw ValidationException::withMessages([
                'site_id' => 'Site not found for this tenant.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function recordConfigurationVersion(DataSource $source, User $user, array $settings, string $reason): void
    {
        $next = (int) DataSourceConfigurationVersion::query()
            ->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->max('version_number') + 1;

        DataSourceConfigurationVersion::query()->create([
            'tenant_id' => $source->tenant_id,
            'data_source_id' => $source->id,
            'version_number' => $next,
            'settings_snapshot' => $settings,
            'changed_by' => $user->id,
            'change_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'duplicate')
            || (string) $e->getCode() === '23000';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function sanitizeResult(array $raw): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'authorization', 'credentials'];
        foreach ($raw as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $raw[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $raw[$key] = $this->sanitizeResult($value);
            }
        }

        return $raw;
    }
}
