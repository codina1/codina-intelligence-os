<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    protected function seedRolesAndPermissions(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    protected function makeTenantUser(string $roleSlug = 'tenant_owner', ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user->fresh(), $tenant->fresh()];
    }

    protected function assertHasPermission(string $roleSlug, string $permission): void
    {
        $role = Role::query()->where('slug', $roleSlug)->with('permissions')->firstOrFail();
        $this->assertTrue($role->permissions->contains('slug', $permission));
        $this->assertInstanceOf(Permission::class, Permission::query()->where('slug', $permission)->first());
    }
}
