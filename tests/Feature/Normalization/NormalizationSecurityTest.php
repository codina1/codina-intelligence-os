<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\Site;
use App\Models\Tenant;
use App\Normalization\Jobs\NormalizationRunJob;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class NormalizationSecurityTest extends TestCase
{
    public function test_cross_tenant_entity_lookup_returns_indistinguishable_404(): void
    {
        [$ownerA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$ownerB] = $this->makeTenantUser('tenant_owner');

        $sourceId = $this->createAndSync($ownerA, $tenantA, 'wordpress', 'posts');
        $this->actingAs($ownerA)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $entityId = ContentEntity::query()->withoutTenantIsolation()->firstOrFail()->public_id;

        $this->actingAs($ownerB)->getJson('/api/v1/inventory/entities/'.$entityId)
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Content entity not found.');

        $this->actingAs($ownerB)->getJson('/api/v1/inventory/summary?tenant_id='.$tenantA->id)
            ->assertForbidden();
    }

    public function test_cross_tenant_normalize_returns_404(): void
    {
        [$ownerA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$ownerB] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($ownerA, $tenantA, 'wordpress', 'posts');

        $this->actingAs($ownerB)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertNotFound();
    }

    public function test_normalize_requires_csrf(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)
            ->withSession(['_token' => 'expected-token'])
            ->withHeader('X-CSRF-TOKEN', 'wrong-token')
            ->postJson('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
    }

    public function test_queued_job_rejects_cross_tenant_user(): void
    {
        [$ownerA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$ownerB] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($ownerA, $tenantA, 'wordpress', 'posts');

        Queue::fake();
        $this->actingAs($ownerA)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $run = \App\Models\NormalizationRun::query()->withoutTenantIsolation()->firstOrFail();

        $job = new NormalizationRunJob((int) $run->id, (int) $tenantA->id, (int) $ownerB->id);
        $job->handle(app(TenantContext::class), app(\App\Normalization\Services\NormalizationOrchestrator::class));

        $run->refresh();
        $this->assertSame('failed', $run->status->value);
        $this->assertSame('unauthorized_execution', $run->last_error_code);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_tenant_context_cleared_after_job(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_permissions_seeded_for_inventory_and_normalize(): void
    {
        $this->assertHasPermission('tenant_owner', 'inventory.view');
        $this->assertHasPermission('tenant_owner', 'normalize.manage');
        $this->assertHasPermission('viewer', 'inventory.view');
        $this->assertHasPermission('content_manager', 'normalize.manage');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createAndSync($user, Tenant $tenant, string $provider, string $entityType, array $settings = []): string
    {
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        $sourceId = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => $provider,
            'display_name' => $provider.' security source',
            'mode' => 'mock',
            'auth_method' => 'none',
            'settings' => array_merge([
                'fixture_dataset' => 'paginated_25',
                'pagination_size' => 25,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
            ], $settings),
        ])->assertCreated()->json('data.id');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => $entityType,
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');

        return $sourceId;
    }
}
