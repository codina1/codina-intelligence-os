<?php

namespace App\Sites\Services;

use App\Audit\Services\AuditLogger;
use App\Identity\Services\AuthorizationService;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SiteService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function listForUser(User $user, ?int $tenantId = null, int $perPage = 15): LengthAwarePaginator
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass(
                $user,
                'sites.list.platform',
                function () use ($tenantId, $perPage) {
                    $query = Site::query()->with('tenant')->orderBy('name');
                    if ($tenantId !== null) {
                        $query->where('tenant_id', $tenantId);
                    }

                    return $query->paginate($perPage);
                }
            );
        }

        $query = $this->membershipScopedQuery($user, $tenantId);

        return $query->paginate($perPage);
    }

    public function findForUser(User $user, int $siteId): Site
    {
        if ($user->isPlatformAdmin()) {
            return $this->tenantContext->runWithPlatformBypass(
                $user,
                'sites.find.platform',
                function () use ($siteId, $user) {
                    $site = Site::query()->with('tenant')->find($siteId);
                    if ($site === null) {
                        throw new NotFoundHttpException('Site not found.');
                    }
                    $this->authorization->assertPermission($user, 'sites.view', (int) $site->tenant_id);

                    return $site;
                }
            );
        }

        // Membership-scoped lookup only — never probe global existence (prevents enumeration).
        $site = $this->membershipScopedQuery($user)->whereKey($siteId)->first();

        if ($site === null) {
            throw new NotFoundHttpException('Site not found.');
        }

        $this->authorization->assertPermission($user, 'sites.view', (int) $site->tenant_id);

        return $site;
    }

    public function create(User $user, array $data): Site
    {
        $tenantId = (int) $data['tenant_id'];
        $this->authorization->assertTenantAccess($user, $tenantId);
        $this->authorization->assertPermission($user, 'sites.manage', $tenantId);

        $this->tenantContext->set(Tenant::query()->findOrFail($tenantId));

        $site = Site::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'primary_url' => $data['primary_url'],
            'status' => $data['status'] ?? 'active',
            'settings' => $data['settings'] ?? [],
        ]);

        $this->auditLogger->log('site.created', $user, $site, [
            'name' => $site->name,
            'slug' => $site->slug,
        ], $tenantId);

        return $site;
    }

    public function update(User $user, int $siteId, array $data): Site
    {
        $site = $this->findForUser($user, $siteId);
        $this->authorization->assertPermission($user, 'sites.manage', (int) $site->tenant_id);

        if (isset($data['tenant_id']) && (int) $data['tenant_id'] !== (int) $site->tenant_id) {
            throw new AccessDeniedHttpException('Cannot move a site across tenants.');
        }

        $this->tenantContext->set($site->tenant);

        $site->fill(collect($data)->only(['name', 'slug', 'primary_url', 'status', 'settings'])->all());
        $site->save();

        $this->auditLogger->log('site.updated', $user, $site, [
            'changes' => array_keys($data),
        ], (int) $site->tenant_id);

        return $site->fresh('tenant');
    }

    public function delete(User $user, int $siteId): void
    {
        $site = $this->findForUser($user, $siteId);
        $this->authorization->assertPermission($user, 'sites.manage', (int) $site->tenant_id);

        $tenantId = (int) $site->tenant_id;
        $this->tenantContext->set($site->tenant);
        $site->delete();

        $this->auditLogger->log('site.deleted', $user, null, [
            'site_id' => $siteId,
        ], $tenantId);
    }

    /**
     * Membership-authorized multi-tenant reads — never uses silent withoutGlobalScopes().
     */
    private function membershipScopedQuery(User $user, ?int $tenantId = null): Builder
    {
        $accessible = $user->accessibleTenantIds();

        if ($tenantId !== null) {
            $this->authorization->assertTenantAccess($user, $tenantId);
            $this->authorization->assertPermission($user, 'sites.view', $tenantId);
            $accessible = [$tenantId];
        }

        if ($accessible === []) {
            throw new AccessDeniedHttpException('No accessible tenants.');
        }

        return Site::query()
            ->withoutTenantIsolation()
            ->with('tenant')
            ->whereIn('tenant_id', $accessible)
            ->orderBy('name');
    }
}
