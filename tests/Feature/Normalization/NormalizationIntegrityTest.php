<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\ContentSourceLink;
use App\Models\ContentVersion;
use App\Models\IngestionRecord;
use App\Models\MetricObservation;
use App\Models\NormalizationFailure;
use App\Models\NormalizationRun;
use App\Models\Site;
use App\Models\Tenant;
use App\Normalization\Enums\NormalizationErrorCode;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class NormalizationIntegrityTest extends TestCase
{
    public function test_normalize_without_completed_sync_fails(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        $sourceId = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'unsynced source',
            'mode' => 'mock',
            'auth_method' => 'none',
            'settings' => [
                'fixture_dataset' => 'paginated_25',
                'pagination_size' => 25,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
            ],
        ])->assertCreated()->json('data.id');

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame('failed', $response->json('data.status'));
        $this->assertSame(NormalizationErrorCode::NoCommittedStaging->value, $response->json('data.last_error_code'));
    }

    public function test_rejected_record_is_recorded_without_raw_payload(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $record = IngestionRecord::query()->withoutTenantIsolation()->where('external_id', '2')->firstOrFail();
        $payload = $record->payload_json;
        unset($payload['title']);
        $record->payload_json = $payload;
        $record->save();

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame('completed_with_errors', $response->json('data.status'));
        $this->assertSame(1, $response->json('data.records_rejected'));
        $this->assertSame(24, $response->json('data.entities_created'));

        $failure = NormalizationFailure::query()->withoutTenantIsolation()->first();
        $this->assertNotNull($failure);
        $this->assertSame(NormalizationErrorCode::MissingRequiredField->value, $failure->error_code);
        $this->assertArrayHasKey('field', $failure->safe_diagnostics_json);
        $this->assertArrayNotHasKey('payload', $failure->safe_diagnostics_json ?? []);
        $this->assertArrayNotHasKey('raw', $failure->safe_diagnostics_json ?? []);
    }

    public function test_source_links_are_provenance_only_and_not_cross_provider(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $wp = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $woo = $this->createAndSync($user, $tenant, 'woocommerce', 'products');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$wp.'/normalize')->assertStatus(202);
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$woo.'/normalize')->assertStatus(202);

        $this->assertSame(50, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(50, ContentSourceLink::query()->withoutTenantIsolation()->count());

        $wpEntity = ContentEntity::query()->withoutTenantIsolation()->where('content_type', 'post')->firstOrFail();
        $link = ContentSourceLink::query()->withoutTenantIsolation()
            ->where('content_entity_id', $wpEntity->id)
            ->firstOrFail();
        $this->assertSame('primary', $link->relation_type);
        $this->assertSame($wpEntity->data_source_id, $link->data_source_id);
    }

    public function test_metric_upsert_is_idempotent(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'google_search_console', 'search_analytics');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.metrics_created', 25);

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame(0, $second->json('data.metrics_created'), json_encode($second->json('data')));
        $this->assertSame(0, $second->json('data.metrics_updated'), json_encode($second->json('data')));
        $this->assertSame(25, MetricObservation::query()->withoutTenantIsolation()->count());
    }

    #[Group('critical')]
    public function test_schema_tables_exist_and_runs_are_tenant_scoped(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertDatabaseCount('content_entities', 25);
        $this->assertDatabaseCount('content_versions', 25);
        $this->assertDatabaseCount('content_source_links', 25);
        $this->assertSame(1, NormalizationRun::query()->withoutTenantIsolation()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, ContentVersion::query()->withoutTenantIsolation()->where('tenant_id', '!=', $tenant->id)->count());
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
            'display_name' => $provider.' integrity source',
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
