<?php

namespace Tests\Feature\Synchronization;

use App\Models\DataSource;
use App\Models\Site;
use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Services\CheckpointManager;
use App\Synchronization\Services\SyncLeaseManager;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class SyncConcurrencyTest extends TestCase
{
    public function test_two_workers_cannot_own_same_active_scope(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');

        $runA = $this->makeRun($tenant, $source, 'posts', 'exclusive');
        $runB = $this->makeRun($tenant, $source, 'posts', 'exclusive');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($runA);
        $leaseA = $leases->acquire($runA, 'worker-a');

        $this->expectException(\DomainException::class);
        try {
            $leases->acquire($runB, 'worker-b');
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->assertSame('worker-a', $leaseA['owner']);
    }

    public function test_lease_expiration_recovery_works(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $runA = $this->makeRun($tenant, $source, 'posts', 'expire');
        $runB = $this->makeRun($tenant, $source, 'posts', 'expire');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($runA);
        $first = $leases->acquire($runA, 'worker-old');
        $leases->expireLeaseForTest($runA);

        $second = $leases->acquire($runB, 'worker-new');
        app(TenantContext::class)->clear();

        $this->assertSame('worker-new', $second['owner']);
        $this->assertGreaterThan($first['generation'], $second['generation']);
    }

    public function test_fencing_blocks_stale_worker_commits(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $runA = $this->makeRun($tenant, $source, 'posts', 'fence');
        $runB = $this->makeRun($tenant, $source, 'posts', 'fence');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-worker');
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-worker');

        $this->expectException(\DomainException::class);
        try {
            $leases->assertOwnership($runA->fresh(), $stale['owner'], $stale['generation']);
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->assertSame('fresh-worker', $fresh['owner']);
    }

    public function test_checkpoints_cannot_regress(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $run = $this->makeRun($tenant, $source, 'posts', 'checkpoint');

        app(TenantContext::class)->set($tenant);
        $manager = app(CheckpointManager::class);
        $checkpoint = $manager->getOrCreate($run);
        $manager->commitPage($run, $checkpoint, 3, ['page' => 4]);

        $this->expectException(\DomainException::class);
        try {
            $manager->commitPage($run, $checkpoint->fresh(), 2, ['page' => 3]);
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->assertSame(3, SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('last_completed_page'));
    }

    public function test_active_scope_conflict_via_api(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $run = $this->makeRun($tenant, $source, 'posts', 'api-conflict');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($run);
        $leases->acquire($run, 'holder');
        // Keep lease alive and mark run running so orchestrator sees conflict.
        $run->forceFill(['status' => SyncRunStatus::Running])->save();
        app(TenantContext::class)->clear();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$source->public_id.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'api-conflict',
        ])->assertStatus(409);
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
            'display_name' => $provider.' concurrency',
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
            ],
        ]);
        app(TenantContext::class)->clear();

        return $run;
    }
}
