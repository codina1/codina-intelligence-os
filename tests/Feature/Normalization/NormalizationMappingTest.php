<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\ContentVersion;
use App\Models\IngestionRecord;
use App\Models\MetricObservation;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NormalizationMappingTest extends TestCase
{
    public function test_wordpress_posts_normalize_to_content_entities(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame(25, $response->json('data.records_scanned'));
        $this->assertSame(25, $response->json('data.entities_created'));
        $this->assertSame(25, $response->json('data.versions_created'));
        $this->assertTrue($response->json('data.is_simulated'));

        $this->assertSame(25, ContentEntity::query()->withoutTenantIsolation()->count());
        $entity = ContentEntity::query()->withoutTenantIsolation()->first();
        $this->assertSame('post', $entity->content_type);
        $this->assertNotNull($entity->title);
        $this->assertNull($entity->slug);
        $this->assertNull($entity->canonical_url);
        $this->assertSame(25, ContentVersion::query()->withoutTenantIsolation()->count());
    }

    public function test_all_five_providers_normalize_from_staging(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $map = [
            'wordpress' => ['posts', 'entities'],
            'woocommerce' => ['products', 'entities'],
            'tutor_lms_pro' => ['courses', 'entities'],
            'google_search_console' => ['search_analytics', 'metrics'],
            'google_analytics_4' => ['reports', 'metrics'],
        ];

        foreach ($map as $provider => [$entity, $kind]) {
            $sourceId = $this->createAndSync($user, $tenant, $provider, $entity);
            $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
                ->assertStatus(202)
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.records_scanned', 25);

            if ($kind === 'entities') {
                $this->assertSame(25, $response->json('data.entities_created'));
            } else {
                $this->assertSame(25, $response->json('data.metrics_created'));
            }
        }

        $this->assertSame(75, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(50, MetricObservation::query()->withoutTenantIsolation()->count());
        $this->assertSame('product', ContentEntity::query()->withoutTenantIsolation()->where('content_type', 'product')->first()->content_type);
        $this->assertSame('course', ContentEntity::query()->withoutTenantIsolation()->where('content_type', 'course')->first()->content_type);
    }

    public function test_idempotent_renormalize_does_not_duplicate_versions(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_created', 25);

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame(0, $second->json('data.entities_created'));
        $this->assertSame(0, $second->json('data.versions_created'));
        $this->assertSame(25, $second->json('data.entities_unchanged'));
        $this->assertSame(25, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(25, ContentVersion::query()->withoutTenantIsolation()->count());
    }

    public function test_content_change_creates_new_version_only(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $record = IngestionRecord::query()->withoutTenantIsolation()->where('external_id', '1')->firstOrFail();
        $payload = $record->payload_json;
        $payload['title'] = 'Updated fixture title';
        $record->payload_json = $payload;
        $record->payload_hash = hash('sha256', json_encode($payload));
        $record->save();

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame(1, $response->json('data.entities_updated'));
        $this->assertSame(1, $response->json('data.versions_created'));
        $this->assertSame(24, $response->json('data.entities_unchanged'));
        $this->assertSame(25, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(26, ContentVersion::query()->withoutTenantIsolation()->count());

        $entity = ContentEntity::query()->withoutTenantIsolation()->where('external_id', '1')->firstOrFail();
        $this->assertSame(2, $entity->current_version_number);
        $this->assertSame('Updated fixture title', $entity->title);
    }

    public function test_gsc_and_ga4_metrics_preserve_zero_and_null_periods(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $gscId = $this->createAndSync($user, $tenant, 'google_search_console', 'search_analytics');

        $record = IngestionRecord::query()->withoutTenantIsolation()
            ->where('external_id', '1')
            ->firstOrFail();
        $payload = $record->payload_json;
        $payload['clicks'] = 0;
        $record->payload_json = $payload;
        $record->payload_hash = hash('sha256', json_encode($payload));
        $record->save();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$gscId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.metrics_created', 25);

        $zero = MetricObservation::query()->withoutTenantIsolation()
            ->where('metric_key', 'clicks')
            ->get()
            ->first(fn ($m) => (float) $m->metric_value === 0.0);
        $this->assertNotNull($zero);
        $this->assertSame('0.000000', (string) $zero->metric_value);
        $this->assertNull($zero->period_start);
        $this->assertNull($zero->period_end);

        $ga4Id = $this->createAndSync($user, $tenant, 'google_analytics_4', 'reports');
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$ga4Id.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.metrics_created', 25);

        $metric = MetricObservation::query()->withoutTenantIsolation()
            ->where('provider', 'google_analytics_4')
            ->first();
        $this->assertNull($metric->period_start);
        $this->assertNull($metric->observed_at);
    }

    public function test_does_not_mutate_ingestion_records(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'woocommerce', 'products');

        $before = IngestionRecord::query()->withoutTenantIsolation()->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'hash' => $r->payload_hash,
            'updated' => optional($r->updated_at)?->toIso8601String(),
        ])->all();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $after = IngestionRecord::query()->withoutTenantIsolation()->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'hash' => $r->payload_hash,
            'updated' => optional($r->updated_at)?->toIso8601String(),
        ])->all();

        $this->assertSame($before, $after);
    }

    public function test_no_external_http_during_normalization(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'tutor_lms_pro', 'courses');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');

        Http::assertNothingSent();
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
            'display_name' => $provider.' inventory source',
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
            'scope_key' => 'default',
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');

        return $sourceId;
    }
}
