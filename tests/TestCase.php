<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        app(TenantContext::class)->clear();
        $this->seedRolesAndPermissions();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    protected function seedRolesAndPermissions(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    /**
     * Attach a valid SPA CSRF token for mutating session-authenticated requests.
     */
    protected function withCsrf(?string $token = 'test-csrf-token'): static
    {
        return $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token);
    }

    protected function postJsonWithCsrf(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->withCsrf()->postJson($uri, $data, $headers);
    }

    protected function patchJsonWithCsrf(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->withCsrf()->patchJson($uri, $data, $headers);
    }

    protected function deleteJsonWithCsrf(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->withCsrf()->deleteJson($uri, $data, $headers);
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
