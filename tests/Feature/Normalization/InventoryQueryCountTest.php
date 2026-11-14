<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\DataSource;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class InventoryQueryCountTest extends TestCase
{
    public function test_entity_listing_avoids_n_plus_one_data_source_lookups(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        $source = DataSource::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'query count source',
            'mode' => 'mock',
            'status' => 'healthy',
            'auth_method' => 'none',
            'settings' => ['fixture_dataset' => 'paginated_25'],
        ]);

        $now = now();
        for ($i = 1; $i <= 100; $i++) {
            ContentEntity::query()->create([
                'public_id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'site_id' => $site->id,
                'data_source_id' => $source->id,
                'entity_type' => 'posts',
                'external_id' => (string) $i,
                'external_id_hash' => hash('sha256', (string) $i),
                'content_type' => 'post',
                'title' => 'Entity '.$i,
                'normalized_status' => 'active',
                'current_version_number' => 1,
                'normalized_payload_hash' => hash('sha256', 'p'.$i),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'normalized_at' => $now,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/inventory/entities?tenant_id='.$tenant->id.'&per_page=100')
            ->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(100, $response->json('data'));
        $this->assertSame(100, $response->json('meta.pagination.total'));

        $dataSourceSelects = collect($queries)->filter(function (array $query) {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from `data_sources`')
                || str_contains($sql, 'from "data_sources"');
        })->count();

        // Eager-load should keep data_sources lookups bounded (not 100).
        $this->assertLessThanOrEqual(3, $dataSourceSelects, 'Expected bounded data_sources queries, got '.$dataSourceSelects);
        $this->assertLessThan(40, count($queries), 'Unexpected total query count: '.count($queries));
        $this->assertSame($source->public_id, $response->json('data.0.data_source_id'));
        $this->assertArrayNotHasKey('settings', $response->json('data.0.provenance') ?? []);
    }
}
