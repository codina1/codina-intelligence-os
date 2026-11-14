<?php

namespace Tests\Feature\Synchronization;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\Site;
use App\Models\SyncActiveScope;
use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Jobs\ExecuteSyncRunJob;
use App\Synchronization\Services\SyncLeaseManager;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class SyncRemediationTest extends TestCase
{
    public function test_multipage_incremental_separates_watermark_from_page_cursor(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 2,
            'incremental_dataset' => 'incremental_multipage',
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'incremental',
            'entity_type' => 'posts',
            'scope_key' => 'mp-inc',
            'options' => [
                'per_page' => 2,
                'incremental_dataset' => 'incremental_multipage',
            ],
        ])->assertStatus(202);

        $this->assertSame('completed', $response->json('data.status'));
        $this->assertSame(5, $response->json('data.rows_inserted'));
        $this->assertSame(3, $response->json('data.pages_completed'));

        $checkpoint = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'mp-inc')
            ->firstOrFail();

        $this->assertSame(['page' => 3], $checkpoint->cursor_json);
        $this->assertSame('2026-01-01T16:00:00Z', $checkpoint->committed_watermark_json['since'] ?? null);
        $this->assertArrayNotHasKey('cursor', $checkpoint->committed_watermark_json ?? []);
        $this->assertArrayNotHasKey('page', $checkpoint->committed_watermark_json ?? []);
    }

    public function test_consecutive_incremental_runs_respect_committed_watermark(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'incremental_dataset' => 'incremental_multipage',
            'pagination_size' => 10,
        ]);

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'incremental',
            'entity_type' => 'posts',
            'scope_key' => 'cont',
            'options' => ['incremental_dataset' => 'incremental_multipage', 'per_page' => 10],
        ])->assertStatus(202);

        $this->assertSame(5, $first->json('data.rows_inserted'));
        $watermark = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'cont')
            ->value('committed_watermark_json');
        $this->assertSame('2026-01-01T16:00:00Z', $watermark['since'] ?? null);

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'incremental',
            'entity_type' => 'posts',
            'scope_key' => 'cont',
            'options' => ['incremental_dataset' => 'incremental_multipage', 'per_page' => 10],
        ])->assertStatus(202);

        $this->assertSame('completed', $second->json('data.status'));
        $this->assertSame(0, $second->json('data.rows_inserted'));
        $this->assertSame(0, $second->json('data.rows_received'));
        $this->assertSame(5, IngestionRecord::query()->withoutTenantIsolation()->count());

        $after = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'cont')
            ->value('committed_watermark_json');
        $this->assertSame('2026-01-01T16:00:00Z', $after['since'] ?? null);
    }

    public function test_partial_incremental_does_not_advance_watermark_and_retry_resumes_pages(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'incremental_dataset' => 'incremental_multipage',
            'pagination_size' => 2,
            'simulated_error_scenario' => 'timeout_page_2',
        ]);

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'incremental',
            'entity_type' => 'posts',
            'scope_key' => 'partial-inc',
            'options' => [
                'per_page' => 2,
                'incremental_dataset' => 'incremental_multipage',
                'error_scenario' => 'timeout_page_2',
            ],
        ])->assertStatus(202);

        $this->assertSame('partial', $first->json('data.status'));
        $this->assertSame(1, $first->json('data.pages_completed'));
        $this->assertSame(2, IngestionRecord::query()->withoutTenantIsolation()->count());

        $checkpoint = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'partial-inc')
            ->firstOrFail();
        $this->assertNull($checkpoint->committed_watermark_json);
        $this->assertSame(1, $checkpoint->last_completed_page);

        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $settings = $source->settings;
        $settings['simulated_error_scenario'] = 'none';
        $source->forceFill(['settings' => $settings])->save();

        $retry = $this->actingAs($user)->postJsonWithCsrf('/api/v1/sync-runs/'.$first->json('data.id').'/retry')
            ->assertStatus(202);

        $this->assertSame('completed', $retry->json('data.status'));
        $this->assertSame(5, IngestionRecord::query()->withoutTenantIsolation()->count());
        $this->assertGreaterThanOrEqual(3, $retry->json('data.pages_completed'));

        $checkpoint->refresh();
        $this->assertSame('2026-01-01T16:00:00Z', $checkpoint->committed_watermark_json['since'] ?? null);
    }

    public function test_cancelled_run_does_not_poison_next_sync_on_same_scope(): void
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
            'scope_key' => 'poison',
        ])->assertStatus(202);

        $runId = $first->json('data.id');
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/sync-runs/'.$runId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('scope_key', 'poison')
            ->firstOrFail();
        $this->assertNull($scope->cancel_requested_at);
        $this->assertNull($scope->sync_run_id);

        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $settings = $source->settings;
        $settings['simulated_error_scenario'] = 'none';
        $source->forceFill(['settings' => $settings])->save();

        $second = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'poison',
        ])->assertStatus(202);

        $this->assertSame('completed', $second->json('data.status'));
        $this->assertSame(25, $second->json('data.rows_inserted') + $second->json('data.rows_unchanged'));
    }

    public function test_cancel_survives_same_run_reacquire_but_clears_on_transfer(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $runA = $this->makeRun($tenant, $source, 'posts', 'cancel-iso');
        $runB = $this->makeRun($tenant, $source, 'posts', 'cancel-iso');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($runA);
        $leaseA = $leases->acquire($runA, 'worker-a');
        $leases->requestCancel($runA, $user->id);

        try {
            $leases->acquire($runA, 'worker-a-retry');
            $this->fail('Expected cancelled DomainException on same-run reacquire');
        } catch (\DomainException $e) {
            $this->assertSame('cancelled', $e->getMessage());
        }

        $leases->expireLeaseForTest($runA);
        $leaseB = $leases->acquire($runB, 'worker-b');
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('scope_key', 'cancel-iso')
            ->firstOrFail();

        $this->assertNull($scope->cancel_requested_at);
        $this->assertSame('worker-b', $leaseB['owner']);
        $this->assertGreaterThan($leaseA['generation'], $leaseB['generation']);
        app(TenantContext::class)->clear();
    }

    public function test_queued_scope_reservation_rejects_second_start(): void
    {
        Queue::fake();

        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 10,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'reserve',
        ])->assertStatus(202);

        Queue::assertPushed(ExecuteSyncRunJob::class);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'reserve',
        ])->assertStatus(409);

        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('scope_key', 'reserve')
            ->firstOrFail();
        $this->assertNotNull($scope->sync_run_id);
        $this->assertNotNull($scope->lease_owner);
    }

    public function test_strict_options_reject_unknown_forbidden_and_traversal(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => ['lease_owner' => 'attacker'],
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => ['unknown_key' => 'x'],
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => ['scenario' => '../etc/passwd'],
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => ['scenario' => 'not_a_real_fixture'],
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => [
                'scenario' => 'empty',
                'per_page' => 5,
                'error_scenario' => 'none',
                'incremental_dataset' => 'incremental',
                'simulated' => true,
            ],
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');
    }

    public function test_idempotent_page_recommit_does_not_double_pages_completed(): void
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
            'scope_key' => 'idem',
        ])->assertStatus(202);

        $this->assertSame(1, $first->json('data.pages_completed'));
        $version = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'idem')
            ->value('version');

        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $settings = $source->settings;
        $settings['simulated_error_scenario'] = 'none';
        $source->forceFill(['settings' => $settings])->save();

        $retry = $this->actingAs($user)->postJsonWithCsrf('/api/v1/sync-runs/'.$first->json('data.id').'/retry')
            ->assertStatus(202);

        $this->assertSame('completed', $retry->json('data.status'));
        $this->assertSame(3, $retry->json('data.pages_completed'));
        $this->assertSame(25, IngestionRecord::query()->withoutTenantIsolation()->count());

        $checkpoint = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('scope_key', 'idem')
            ->firstOrFail();
        $this->assertGreaterThan($version, $checkpoint->version);
        $this->assertSame(3, $checkpoint->last_completed_page);
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
            'display_name' => $provider.' remediation source',
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

    private function seedSource(Tenant $tenant, string $provider): DataSource
    {
        app(TenantContext::class)->set($tenant);
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $source = DataSource::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => $provider,
            'display_name' => $provider.' remediation',
            'mode' => 'mock',
            'status' => 'configured',
            'auth_method' => 'none',
            'settings' => [
                'fixture_dataset' => 'paginated_25',
                'pagination_size' => 10,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
            ],
        ]);
        app(TenantContext::class)->clear();

        return $source;
    }

    private function makeRun(Tenant $tenant, DataSource $source, string $entity, string $scope): SyncRun
    {
        app(TenantContext::class)->set($tenant);
        $run = SyncRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => $entity,
            'scope_key' => $scope,
            'sync_type' => SyncType::Full,
            'status' => SyncRunStatus::Queued,
            'is_simulated' => true,
            'options_json' => [
                'scenario' => 'paginated_25',
                'per_page' => 10,
                'error_scenario' => 'none',
                'incremental_dataset' => 'incremental',
                'simulated' => true,
            ],
        ]);
        app(TenantContext::class)->clear();

        return $run;
    }
}
