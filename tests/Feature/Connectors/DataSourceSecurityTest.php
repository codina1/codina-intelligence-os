<?php

namespace Tests\Feature\Connectors;

use App\Models\AuditLog;
use App\Models\CredentialReference;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class DataSourceSecurityTest extends TestCase
{
    public function test_unauthenticated_requests_rejected(): void
    {
        $this->getJson('/api/v1/data-sources')->assertStatus(401);
        $this->getJson('/api/v1/connector-providers')->assertStatus(401);
        // CSRF middleware runs before auth on mutating routes (same as Phase 01).
        $this->postJson('/api/v1/data-sources', [])->assertStatus(419);
        $this->withCsrf()->postJson('/api/v1/data-sources', [
            'tenant_id' => 1,
            'provider' => 'wordpress',
            'display_name' => 'Unauth',
        ])->assertStatus(401);
    }

    public function test_unauthorized_mutations_rejected_for_viewer_and_analyst(): void
    {
        [$viewer, $tenant] = $this->makeTenantUser('viewer');
        [$analyst] = $this->makeTenantUser('analyst', $tenant);

        foreach ([$viewer, $analyst] as $user) {
            $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
                'tenant_id' => $tenant->id,
                'provider' => 'wordpress',
                'display_name' => 'Nope',
            ])->assertStatus(403);
        }

        [$owner] = $this->makeTenantUser('tenant_owner', $tenant);
        $id = $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Owned',
            'mode' => 'mock',
        ])->assertCreated()->json('data.id');

        $this->actingAs($viewer)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test')->assertStatus(403);
        $this->actingAs($analyst)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/disable')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/data-sources/'.$id)->assertOk();
    }

    public function test_tenant_isolation_and_non_enumeration(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$userB, $tenantB] = $this->makeTenantUser('tenant_owner');

        $idA = $this->actingAs($userA)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenantA->id,
            'provider' => 'wordpress',
            'display_name' => 'A Source',
        ])->assertCreated()->json('data.id');

        $this->actingAs($userB)->getJson('/api/v1/data-sources/'.$idA)->assertStatus(404);
        $this->actingAs($userB)->patchJsonWithCsrf('/api/v1/data-sources/'.$idA, [
            'display_name' => 'Hijack',
        ])->assertStatus(404);
        $this->actingAs($userB)->postJsonWithCsrf('/api/v1/data-sources/'.$idA.'/test')->assertStatus(404);

        $missing = '00000000-0000-4000-8000-000000000099';
        $this->actingAs($userA)->getJson('/api/v1/data-sources/'.$missing)->assertStatus(404);
        $this->actingAs($userB)->getJson('/api/v1/data-sources/'.$missing)->assertStatus(404);
    }

    public function test_site_cross_tenant_reference_rejected(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        $tenantB = Tenant::factory()->create();
        $siteB = Site::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenantA->id,
            'site_id' => $siteB->id,
            'provider' => 'woocommerce',
            'display_name' => 'Bad Site',
        ])->assertStatus(422);
    }

    public function test_csrf_protection_on_mutations(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJson('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'No CSRF',
        ])->assertStatus(419);
    }

    public function test_idempotency_on_create(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $payload = [
            'tenant_id' => $tenant->id,
            'provider' => 'tutor_lms_pro',
            'display_name' => 'Idempotent LMS',
            'mode' => 'mock',
        ];

        $headers = ['Idempotency-Key' => 'ds-create-1'];

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', $payload, $headers)
            ->assertCreated();
        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', $payload, $headers)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DataSource::query()->withoutTenantIsolation()->where('provider', 'tutor_lms_pro')->count());
    }

    public function test_audit_creation_and_no_secret_persistence_or_exposure(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'google_analytics_4',
            'display_name' => 'GA4 Mock',
            'auth_method' => 'none',
            'settings' => [
                'fixture_dataset' => 'success',
                'pagination_size' => 10,
                'reporting_timezone' => 'UTC',
            ],
        ])->assertCreated();

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('secret_reference', $body);
        $this->assertStringNotContainsString('mock:ref:', $body);

        $sourceId = $response->json('data.internal_id');
        $ref = CredentialReference::query()->withoutTenantIsolation()
            ->where('data_source_id', $sourceId)->first();
        $this->assertNotNull($ref);
        $this->assertStringStartsWith('mock:ref:', $ref->secret_reference);
        $this->assertSame('mock', $ref->status);

        $this->assertTrue(AuditLog::query()->where('action', 'data_source.created')->exists());
        foreach (AuditLog::query()->get() as $log) {
            $payload = json_encode($log->payload);
            $this->assertStringNotContainsString('mock:ref:', (string) $payload);
        }

        // Reject real-looking secrets through settings
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Secret Attempt',
            'settings' => [
                'password' => 'hunter2',
            ],
        ])->assertStatus(422);
    }

    public function test_content_manager_cannot_manage_by_default(): void
    {
        [$manager, $tenant] = $this->makeTenantUser('content_manager');

        $this->actingAs($manager)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Manager create',
        ])->assertStatus(403);

        $this->assertHasPermission('viewer', 'connectors.view');
        $this->assertHasPermission('analyst', 'connectors.view');
        $this->assertHasPermission('tenant_owner', 'connectors.manage');
    }

    public function test_platform_admin_bypass_is_audited(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Platform Visible',
        ])->assertCreated()->json('data.id');

        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->getJson('/api/v1/data-sources/'.$id)->assertOk();
        $this->assertTrue(
            AuditLog::query()->where('action', 'platform.tenant_bypass')->exists()
        );
    }
}
