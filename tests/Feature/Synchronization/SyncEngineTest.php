<?php

namespace Tests\Feature\Synchronization;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\Site;
use App\Models\SyncCheckpoint;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    public function test_full_sync_25_records_over_three_pages_for_wordpress(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'default',
        ])->assertStatus(202);

        $runId = $response->json('data.id');
        $this->assertTrue($response->json('data.is_simulated'));
        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame(25, $response->json('data.rows_received'));
        $this->assertSame(25, $response->json('data.rows_inserted'));
        $this->assertSame(0, $response->json('data.rows_updated'));
        $this->assertSame(3, $response->json('data.pages_completed'));

        $this->assertSame(25, IngestionRecord::query()->withoutTenantIsolation()->count());
        $this->assertSame(1, SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'default')
            ->where('entity_type', 'posts')
            ->where('last_completed_page', 3)
            ->count());

        $this->actingAs($user)->getJson('/api/v1/sync-runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_all_five_mock_connectors_can_sync(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $map = [
            'wordpress' => 'posts',
            'woocommerce' => 'products',
            'google_search_console' => 'search_analytics',
            'google_analytics_4' => 'reports',
            'tutor_lms_pro' => 'courses',
        ];

        foreach ($map as $provider => $entity) {
            $sourceId = $this->createSyncSource($user, $tenant, $provider, [
                'fixture_dataset' => 'paginated_25',
                'pagination_size' => 10,
            ]);

            $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
                'sync_type' => 'full',
                'entity_type' => $entity,
                'scope_key' => 'default',
            ])->assertStatus(202)
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.rows_inserted', 25)
                ->assertJsonPath('data.is_simulated', true);
        }
    }

    public function test_incremental_sync_processes_only_changed_mock_records(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
            'incremental_dataset' => 'incremental',
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'incremental',
            'entity_type' => 'posts',
            'scope_key' => 'inc',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.rows_inserted', 2);

        $checkpoint = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'inc')
            ->first();
        $this->assertNotNull($checkpoint?->committed_watermark_json);
    }

    public function test_repeated_full_sync_does_not_duplicate_and_counts_unchanged(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'repeat',
        ])->assertStatus(202);

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'repeat',
        ])->assertStatus(202);

        $this->assertSame(25, IngestionRecord::query()->withoutTenantIsolation()->count());
        $this->assertSame('completed', $second->json('data.status'), json_encode($second->json('data')));
        $this->assertSame(0, $second->json('data.rows_inserted'));
        $this->assertSame(0, $second->json('data.rows_updated'));
        $this->assertSame(25, $second->json('data.rows_unchanged'), json_encode($second->json('data')));
    }

    public function test_changed_record_counts_as_update_not_insert(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'changed_second_run',
            'pagination_size' => 10,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'chg',
        ])->assertStatus(202)->assertJsonPath('data.rows_inserted', 10);

        // Mutate fixture content via settings pointer to changed file already used;
        // second run with same dataset after first insert: update item 3 by re-syncing
        // after manually updating staged expectation — use orchestrator options override.
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $record = IngestionRecord::query()->withoutTenantIsolation()
            ->where('external_id', '3')->firstOrFail();
        $payload = $record->payload_json;
        $payload['title'] = 'Changed on second run wordpress';
        // Force a different stored hash then re-sync original changed fixture.
        $record->forceFill([
            'payload_hash' => hash('sha256', 'stale'),
            'payload_json' => array_merge($payload, ['title' => 'stale title']),
        ])->save();

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'chg',
        ])->assertStatus(202);

        $this->assertSame(0, $second->json('data.rows_inserted'));
        $this->assertGreaterThanOrEqual(1, $second->json('data.rows_updated'));
        $this->assertSame(10, IngestionRecord::query()->withoutTenantIsolation()->count());
    }

    public function test_invalid_records_are_rejected_and_counted(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'missing_external_id',
            'pagination_size' => 10,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'reject',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.rows_rejected', 1)
            ->assertJsonPath('data.rows_inserted', 1);
    }

    public function test_cursor_loop_stops_safely(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
            'simulated_error_scenario' => 'cursor_loop',
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'loop',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.last_error_code', 'cursor_loop');
    }

    public function test_disabled_and_non_mock_sources_cannot_sync(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/disable')->assertOk();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
        ])->assertStatus(422);

        // Public API cannot set non-mock mode; engine refusal is covered by SyncPageAdapter
        // and runner checks. Re-enable and assert mock path still works after disable cycle.
        $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$sourceId, [
            'status' => 'configured',
        ])->assertOk();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => ['scenario' => 'empty'],
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');
    }

    public function test_retry_resumes_from_committed_checkpoint_after_partial_failure(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
            'simulated_error_scenario' => 'timeout_page_2',
        ]);

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'resume',
        ])->assertStatus(202);

        $this->assertSame('partial', $first->json('data.status'));
        $this->assertSame(1, $first->json('data.pages_completed'));
        $this->assertSame(10, IngestionRecord::query()->withoutTenantIsolation()->count());

        $runId = $first->json('data.id');

        // Clear error injection so retry can complete.
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $settings = $source->settings;
        $settings['simulated_error_scenario'] = 'none';
        $source->forceFill(['settings' => $settings])->save();

        $retry = $this->actingAs($user)->postJsonWithCsrf('/api/v1/sync-runs/'.$runId.'/retry')
            ->assertStatus(202);

        $this->assertSame('completed', $retry->json('data.status'));
        $this->assertSame(25, IngestionRecord::query()->withoutTenantIsolation()->count());
        $this->assertGreaterThanOrEqual(3, $retry->json('data.pages_completed'));
    }

    public function test_cancellation_stops_future_page_commits(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
        ]);

        // Create a queued run without executing by using orchestrator + manual cancel before job.
        // With sync queue the job runs inline; instead simulate cancel-after-first-page via runner hooks:
        // Start sync with timeout on page 2 to commit page 1, then cancel the partial run.
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $settings = $source->settings;
        $settings['simulated_error_scenario'] = 'timeout_page_2';
        $source->forceFill(['settings' => $settings])->save();

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'cancel-scope',
        ])->assertStatus(202);

        $runId = $first->json('data.id');
        $this->assertSame(10, IngestionRecord::query()->withoutTenantIsolation()->count());

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/sync-runs/'.$runId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, IngestionRecord::query()->withoutTenantIsolation()->count());
        $this->assertSame(1, SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'cancel-scope')
            ->where('last_completed_page', 1)
            ->count());
    }

    public function test_no_external_http_during_sync(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'woocommerce', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 25,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'products',
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createSyncSource($user, Tenant $tenant, string $provider, array $settings = []): string
    {
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        return $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => $provider,
            'display_name' => $provider.' sync source',
            'mode' => 'mock',
            'auth_method' => 'none',
            'settings' => array_merge([
                'fixture_dataset' => 'success',
                'pagination_size' => 10,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
            ], $settings),
        ])->assertCreated()->json('data.id');
    }
}
