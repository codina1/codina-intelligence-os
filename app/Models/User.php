<?php

namespace App\Models;

use App\Identity\Enums\RoleSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_platform_admin',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function membershipFor(int $tenantId): ?TenantMembership
    {
        return $this->memberships()
            ->with('role.permissions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();
    }

    public function hasPermissionInTenant(string $permission, int $tenantId): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        $membership = $this->membershipFor($tenantId);

        if ($membership === null || $membership->role === null) {
            return false;
        }

        return $membership->role->hasPermission($permission);
    }

    public function hasRoleInTenant(RoleSlug|string $role, int $tenantId): bool
    {
        $slug = $role instanceof RoleSlug ? $role->value : $role;
        $membership = $this->membershipFor($tenantId);

        return $membership?->role?->slug === $slug;
    }

    public function accessibleTenantIds(): array
    {
        if ($this->isPlatformAdmin()) {
            return Tenant::query()->pluck('id')->all();
        }

        return $this->memberships()
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->all();
    }
}
