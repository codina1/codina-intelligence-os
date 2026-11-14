<?php

namespace App\Sites\Services;

use App\Audit\Services\AuditLogger;
use App\Identity\Services\AuthorizationService;
use App\Models\Site;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        $query = Site::query()->withoutGlobalScopes()->with('tenant')->orderBy('name');

        if ($user->isPlatformAdmin()) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
        } else {
            $accessible = $user->accessibleTenantIds();
            if ($tenantId !== null) {
                $this->authorization->assertTenantAccess($user, $tenantId);
                $this->authorization->assertPermission($user, 'sites.view', $tenantId);
                $query->where('tenant_id', $tenantId);
            } else {
                $query->whereIn('tenant_id', $accessible);
            }
        }

        return $query->paginate($perPage);
    }

    public function findForUser(User $user, int $siteId): Site
    {
        $site = Site::query()->withoutGlobalScopes()->with('tenant')->find($siteId);

        if ($site === null) {
            throw new NotFoundHttpException('Site not found.');
        }

        $this->authorization->assertTenantAccess($user, (int) $site->tenant_id);
        $this->authorization->assertPermission($user, 'sites.view', (int) $site->tenant_id);

        return $site;
    }

    public function create(User $user, array $data): Site
    {
        $tenantId = (int) $data['tenant_id'];
        $this->authorization->assertTenantAccess($user, $tenantId);
        $this->authorization->assertPermission($user, 'sites.manage', $tenantId);

        $this->tenantContext->set(
            \App\Models\Tenant::query()->findOrFail($tenantId)
        );

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
        $site->delete();

        $this->auditLogger->log('site.deleted', $user, null, [
            'site_id' => $siteId,
        ], $tenantId);
    }
}
