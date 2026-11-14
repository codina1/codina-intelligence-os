<?php

namespace Tests\Feature\Tenancy;

use App\Models\Site;
use App\Models\Tenant;
use App\Synchronization\Jobs\TenantAwareJob;
use App\Tenancy\Services\TenantContext;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_tenant_creation_by_platform_admin(): void
    {
        $admin = \App\Models\User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/tenants', [
                'name' => 'New Tenant',
                'slug' => 'new-tenant',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'new-tenant');

        $this->assertDatabaseHas('tenants', ['slug' => 'new-tenant']);
    }

    public function test_site_membership_and_listing_is_tenant_scoped(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$userB, $tenantB] = $this->makeTenantUser('tenant_owner');

        $siteA = Site::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'A Site']);
        $siteB = Site::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'B Site']);

        $response = $this->actingAs($userA)->getJson('/api/v1/sites');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($siteA->id, $ids);
        $this->assertNotContains($siteB->id, $ids);
    }

    public function test_idor_prevention_on_site_show_update_delete(): void
    {
        [$userA] = $this->makeTenantUser('tenant_owner');
        [, $tenantB] = $this->makeTenantUser('tenant_owner');
        $siteB = Site::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson('/api/v1/sites/'.$siteB->id)
            ->assertStatus(403);

        $this->actingAs($userA)->patchJson('/api/v1/sites/'.$siteB->id, [
            'name' => 'Hacked',
        ])->assertStatus(403);

        $this->actingAs($userA)->deleteJson('/api/v1/sites/'.$siteB->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('sites', ['id' => $siteB->id, 'name' => $siteB->name]);
    }

    public function test_cross_tenant_fk_protection_on_create(): void
    {
        [$userA] = $this->makeTenantUser('tenant_owner');
        $tenantB = Tenant::factory()->create();

        $this->actingAs($userA)->postJson('/api/v1/sites', [
            'tenant_id' => $tenantB->id,
            'name' => 'Cross Tenant Site',
            'primary_url' => 'https://cross.example.test',
        ])->assertStatus(403);
    }

    public function test_tenant_context_inside_queued_jobs(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $foreign = Tenant::factory()->create();

        $job = new TenantAwareJob($tenant->id, $user->id, 'sync.ping');
        $result = $job->handle(app(TenantContext::class));

        $this->assertTrue($result['ok']);
        $this->assertSame($tenant->id, $result['tenant_id']);

        $this->expectException(\RuntimeException::class);
        (new TenantAwareJob($foreign->id, $user->id))->handle(app(TenantContext::class));
    }

    public function test_tenant_index_hides_foreign_tenants(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        Tenant::factory()->create(['slug' => 'hidden-tenant']);

        $response = $this->actingAs($userA)->getJson('/api/v1/tenants')->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains($tenantA->slug, $slugs);
        $this->assertNotContains('hidden-tenant', $slugs);
    }
}
