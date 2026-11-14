<?php

namespace App\Tenancy\Services;

use App\Audit\Services\AuditLogger;
use App\Identity\Services\AuthorizationService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, Tenant>
     */
    public function listForUser(User $user): Collection
    {
        if ($user->isPlatformAdmin()) {
            return Tenant::query()->orderBy('name')->get();
        }

        return Tenant::query()
            ->whereIn('id', $user->accessibleTenantIds())
            ->orderBy('name')
            ->get();
    }

    public function findForUser(User $user, int $tenantId): Tenant
    {
        $this->authorization->assertTenantAccess($user, $tenantId);

        return Tenant::query()->findOrFail($tenantId);
    }

    public function create(User $actor, array $data): Tenant
    {
        if (! $actor->isPlatformAdmin()) {
            $this->authorization->assertPermission($actor, 'platform.admin');
        }

        $tenant = Tenant::query()->create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'status' => $data['status'] ?? 'active',
            'settings' => $data['settings'] ?? [],
        ]);

        $this->auditLogger->log('tenant.created', $actor, $tenant, [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ], $tenant->id);

        return $tenant;
    }
}
