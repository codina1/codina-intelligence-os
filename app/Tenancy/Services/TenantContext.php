<?php

namespace App\Tenancy\Services;

use App\Audit\Services\AuditLogger;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\TenantContextRequiredException;
use RuntimeException;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?int $membershipId = null;

    private bool $platformBypass = false;

    public function set(?Tenant $tenant, ?int $membershipId = null): void
    {
        $this->tenant = $tenant;
        $this->membershipId = $membershipId;
    }

    /**
     * Scoped tenant execution with guaranteed restoration (no cross-request leak).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithTenant(?Tenant $tenant, callable $callback, ?int $membershipId = null): mixed
    {
        $previousTenant = $this->tenant;
        $previousMembership = $this->membershipId;

        $this->set($tenant, $membershipId);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->membershipId = $previousMembership;
        }
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membershipId = null;
        $this->platformBypass = false;
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

    public function isPlatformBypassActive(): bool
    {
        return $this->platformBypass;
    }

    public function requireTenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new TenantContextRequiredException('Tenant context is not set.');
        }

        return $this->tenant;
    }

    /**
     * Explicit, audited platform-wide access that temporarily bypasses TenantScope.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runWithPlatformBypass(User $actor, string $reason, callable $callback): mixed
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Platform bypass requires a platform admin.');
        }

        app(AuditLogger::class)->log('platform.tenant_bypass', $actor, null, [
            'reason' => $reason,
        ]);

        $previousTenant = $this->tenant;
        $previousMembership = $this->membershipId;
        $previousBypass = $this->platformBypass;

        $this->platformBypass = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->membershipId = $previousMembership;
            $this->platformBypass = $previousBypass;
        }
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
