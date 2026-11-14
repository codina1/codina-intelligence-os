<?php

namespace Tests\Feature\Tenancy;

use App\Models\Site;
use App\Models\Tenant;
use App\Synchronization\Jobs\TenantAwareJob;
use App\Tenancy\Exceptions\TenantContextRequiredException;
use App\Tenancy\Services\TenantContext;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_tenant_creation_by_platform_admin(): void
    {
        $admin = \App\Models\User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJsonWithCsrf('/api/v1/tenants', [
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

    #[Group('critical')]
    public function test_idor_prevention_on_site_show_update_delete(): void
    {
        [$userA] = $this->makeTenantUser('tenant_owner');
        [, $tenantB] = $this->makeTenantUser('tenant_owner');
        $siteB = Site::factory()->create(['tenant_id' => $tenantB->id]);

        // Cross-tenant access must not enumerate existence — consistent 404.
        $this->actingAs($userA)->getJson('/api/v1/sites/'.$siteB->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->actingAs($userA)->patchJsonWithCsrf('/api/v1/sites/'.$siteB->id, [
            'name' => 'Hacked',
        ])->assertStatus(404);

        $this->actingAs($userA)->deleteJsonWithCsrf('/api/v1/sites/'.$siteB->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('sites', ['id' => $siteB->id, 'name' => $siteB->name]);
    }

    #[Group('critical')]
    public function test_foreign_and_missing_sites_are_indistinguishable(): void
    {
        [$userA] = $this->makeTenantUser('tenant_owner');
        [, $tenantB] = $this->makeTenantUser('tenant_owner');
        $foreign = Site::factory()->create(['tenant_id' => $tenantB->id]);
        $missingId = $foreign->id + 999999;

        $foreignResponse = $this->actingAs($userA)->getJson('/api/v1/sites/'.$foreign->id);
        $missingResponse = $this->actingAs($userA)->getJson('/api/v1/sites/'.$missingId);

        $foreignResponse->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $missingResponse->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->assertSame(
            $foreignResponse->json('error.message'),
            $missingResponse->json('error.message')
        );
        $this->assertSame('Site not found.', $foreignResponse->json('error.message'));
    }

    public function test_cross_tenant_fk_protection_on_create(): void
    {
        [$userA] = $this->makeTenantUser('tenant_owner');
        $tenantB = Tenant::factory()->create();

        $this->actingAs($userA)->postJsonWithCsrf('/api/v1/sites', [
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
        $this->assertNull(app(TenantContext::class)->id(), 'TenantContext must be cleared after job');

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

    public function test_missing_tenant_context_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $tenant->id]);

        app(TenantContext::class)->clear();

        $this->expectException(TenantContextRequiredException::class);
        Site::query()->get();
    }

    public function test_stale_tenant_context_does_not_leak_after_clear(): void
    {
        $tenant = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Scoped']);

        $context = app(TenantContext::class);
        $context->set($tenant);
        $this->assertCount(1, Site::query()->get());

        $context->clear();
        $this->expectException(TenantContextRequiredException::class);
        Site::query()->get();
    }

    public function test_platform_bypass_is_audited(): void
    {
        $admin = \App\Models\User::factory()->platformAdmin()->create();
        $tenant = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $tenant->id]);

        $count = app(TenantContext::class)->runWithPlatformBypass($admin, 'test.bypass', function () {
            return Site::query()->count();
        });

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'platform.tenant_bypass',
        ]);
        $this->assertFalse(app(TenantContext::class)->isPlatformBypassActive());
    }
}
