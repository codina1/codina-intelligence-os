<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationAuthorizationTest extends TestCase
{
    public function test_user_can_authenticate_with_session(): void
    {
        $user = User::factory()->create([
            'email' => 'login@codina.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJsonWithCsrf('/api/v1/auth/login', [
            'email' => 'login@codina.local',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'login@codina.local')
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'schema_version']]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'login@codina.local',
            'password' => Hash::make('password'),
        ]);

        $this->postJsonWithCsrf('/api/v1/auth/login', [
            'email' => 'login@codina.local',
            'password' => 'wrong',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/tenants')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_role_permissions_are_seeded(): void
    {
        $this->assertHasPermission('tenant_owner', 'sites.manage');
        $this->assertHasPermission('viewer', 'sites.view');
        $this->assertHasPermission('platform_admin', 'platform.admin');
    }

    public function test_viewer_cannot_manage_sites(): void
    {
        [$viewer, $tenant] = $this->makeTenantUser('viewer');

        $this->actingAs($viewer)
            ->postJsonWithCsrf('/api/v1/sites', [
                'tenant_id' => $tenant->id,
                'name' => 'Denied Site',
                'primary_url' => 'https://denied.example.test',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }
}
