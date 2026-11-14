<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\Site;
use App\Models\Tenant;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    public function test_inventory_summary_and_entity_listing_after_normalize(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->actingAs($user)->getJson('/api/v1/inventory/summary?tenant_id='.$tenant->id)
            ->assertOk()
            ->assertJsonPath('data.content_entities', 25)
            ->assertJsonPath('data.is_simulated', true)
            ->assertJsonPath('data.by_content_type.post', 25);

        $list = $this->actingAs($user)->getJson('/api/v1/inventory/entities?tenant_id='.$tenant->id.'&content_type=post&per_page=10')
            ->assertOk();
        $this->assertCount(10, $list->json('data'));
        $this->assertSame(25, $list->json('meta.pagination.total'));

        $publicId = $list->json('data.0.id');
        $this->actingAs($user)->getJson('/api/v1/inventory/entities/'.$publicId)
            ->assertOk()
            ->assertJsonPath('data.content_type', 'post')
            ->assertJsonPath('data.mock_only', true);

        $this->actingAs($user)->getJson('/api/v1/inventory/entities/'.$publicId.'/versions')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_metrics_and_data_quality_endpoints(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'google_search_console', 'search_analytics');
        $run = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);
        $runId = $run->json('data.id');

        $this->actingAs($user)->getJson('/api/v1/normalization-runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metrics_created', 25);

        $this->actingAs($user)->getJson('/api/v1/inventory/metrics?tenant_id='.$tenant->id.'&provider=google_search_console')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 25)
            ->assertJsonPath('data.0.linked_content_entity_id', null);

        $quality = $this->actingAs($user)->getJson('/api/v1/inventory/data-quality?tenant_id='.$tenant->id)
            ->assertOk();
        $this->assertTrue($quality->json('data.mock_only'));
        $this->assertArrayHasKey('missing', $quality->json('data.distinctions'));
        $this->assertSame(25, $quality->json('data.totals.metric_observations'));
    }

    #[Group('critical')]
    public function test_viewer_can_read_inventory_but_not_normalize(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        [$viewer] = $this->makeTenantUser('viewer', $tenant);
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');
        $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->actingAs($viewer)->getJson('/api/v1/inventory/summary?tenant_id='.$tenant->id)
            ->assertOk();

        $this->actingAs($viewer)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertForbidden();
    }

    public function test_analyst_cannot_normalize(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        [$analyst] = $this->makeTenantUser('analyst', $tenant);
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');

        $this->actingAs($analyst)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertForbidden();
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
            'display_name' => $provider.' api source',
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
