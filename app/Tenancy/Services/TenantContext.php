<?php

namespace App\Tenancy\Services;

use App\Models\Tenant;
use App\Models\User;
use RuntimeException;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?int $membershipId = null;

    public function set(?Tenant $tenant, ?int $membershipId = null): void
    {
        $this->tenant = $tenant;
        $this->membershipId = $membershipId;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membershipId = null;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function membershipId(): ?int
    {
        return $this->membershipId;
    }

    public function requireTenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new RuntimeException('Tenant context is not set.');
        }

        return $this->tenant;
    }

    public function resolveForUser(User $user, ?int $requestedTenantId = null): Tenant
    {
        if ($user->isPlatformAdmin()) {
            if ($requestedTenantId === null) {
                throw new RuntimeException('Platform admin must select a tenant context.');
            }

            $tenant = Tenant::query()->findOrFail($requestedTenantId);
            $this->set($tenant);

            return $tenant;
        }

        $memberships = $user->memberships()->with('tenant')->get();

        if ($memberships->isEmpty()) {
            throw new RuntimeException('User has no tenant memberships.');
        }

        if ($requestedTenantId !== null) {
            $membership = $memberships->firstWhere('tenant_id', $requestedTenantId);

            if ($membership === null) {
                throw new RuntimeException('User is not a member of the requested tenant.');
            }

            $this->set($membership->tenant, $membership->id);

            return $membership->tenant;
        }

        $membership = $memberships->first();
        $this->set($membership->tenant, $membership->id);

        return $membership->tenant;
    }
}
