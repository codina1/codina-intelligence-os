<?php

namespace Tests\Feature\Synchronization;

use App\Models\DataSource;
use App\Models\Site;
use App\Models\SyncActiveScope;
use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Services\CheckpointManager;
use App\Synchronization\Services\SyncLeaseManager;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * True two-connection MariaDB fencing interleaving (no wrapping RefreshDatabase txn).
 */
#[Group('critical')]
class SyncLeaseFencingMariaDbTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        // Committed rows (no wrapping txn) must not pollute later RefreshDatabase tests.
        foreach ([
            'ingestion_records',
            'ingestion_batches',
            'sync_attempts',
            'sync_checkpoints',
            'sync_active_scopes',
            'sync_runs',
            'data_sources',
            'sites',
            'audit_logs',
            'tenant_memberships',
            'tenants',
            'users',
        ] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_stale_worker_cannot_pass_locked_fencing_after_ownership_transfer(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $runA = $this->makeRun($tenant, $source, 'posts', 'fence-mdb');
        $runB = $this->makeRun($tenant, $source, 'posts', 'fence-mdb');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $checkpoints = app(CheckpointManager::class);
        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-worker');
        $checkpoint = $checkpoints->getOrCreate($runA);
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-worker');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );
        $userName = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        $pdoStale = new \PDO($dsn, $userName, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdoFresh = new \PDO($dsn, $userName, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $beforeVersion = (int) SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('version');

        // Controlled interleaving on two PDO connections:
        // 1) Stale begins txn and locks the scope row with its outdated generation view.
        // 2) Fresh waits on FOR UPDATE until stale rolls back.
        // 3) After rollback, fresh sees transferred ownership; stale fencing tokens fail.
        $pdoStale->beginTransaction();
        $stmtStale = $pdoStale->prepare(
            'SELECT id, sync_run_id, lease_owner, generation
             FROM sync_active_scopes
             WHERE data_source_id = ? AND entity_type = ? AND scope_key = ?
             FOR UPDATE'
        );
        $stmtStale->execute([$source->id, 'posts', 'fence-mdb']);
        $staleView = $stmtStale->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($staleView);

        // Stale generation no longer matches DB ownership (fresh already owns after expire+acquire).
        $staleOwns = (int) $staleView['sync_run_id'] === (int) $runA->id
            && $staleView['lease_owner'] === $stale['owner']
            && (int) $staleView['generation'] === (int) $stale['generation'];
        $this->assertFalse($staleOwns, 'Stale fencing token must not match after ownership transfer');

        // Attempt a forbidden checkpoint bump under stale's open txn (raw SQL) — must not commit.
        $pdoStale->prepare(
            'UPDATE sync_checkpoints SET last_completed_page = 99, version = version + 1, updated_at = NOW() WHERE id = ?'
        )->execute([$checkpoint->id]);

        $pdoFresh->beginTransaction();
        $stmtFresh = $pdoFresh->prepare(
            'SELECT id, sync_run_id, lease_owner, generation
             FROM sync_active_scopes
             WHERE data_source_id = ? AND entity_type = ? AND scope_key = ?
             FOR UPDATE'
        );
        // Fresh blocks until stale releases — roll back stale (discards illegal checkpoint bump).
        $pdoStale->rollBack();
        $stmtFresh->execute([$source->id, 'posts', 'fence-mdb']);
        $freshRow = $stmtFresh->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($freshRow);
        $this->assertSame((int) $runB->id, (int) $freshRow['sync_run_id']);
        $this->assertSame('fresh-worker', $freshRow['lease_owner']);
        $this->assertSame((int) $fresh['generation'], (int) $freshRow['generation']);
        $pdoFresh->commit();

        // Laravel fencing API rejects stale tokens.
        try {
            $leases->assertOwnershipLocked($runA->fresh(), $stale['owner'], $stale['generation']);
            $this->fail('Stale assertOwnershipLocked should throw');
        } catch (\DomainException $e) {
            $this->assertSame('lease_lost', $e->getMessage());
        }

        // Checkpoint unchanged by rolled-back stale write.
        $this->assertSame($beforeVersion, (int) SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('version'));
        $this->assertSame(0, (int) SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('last_completed_page'));

        // Fresh owner can fence and commit a page.
        DB::transaction(function () use ($leases, $checkpoints, $runB, $fresh, $checkpoint) {
            $leases->assertOwnershipLocked($runB->fresh(), $fresh['owner'], $fresh['generation']);
            $checkpoints->commitPage($runB, $checkpoint->fresh(), 1, ['page' => 2]);
        });

        $this->assertSame($beforeVersion + 1, (int) SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('version'));
        $this->assertSame(1, (int) SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)->value('last_completed_page'));

        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('scope_key', 'fence-mdb')->firstOrFail();
        $this->assertSame('fresh-worker', $scope->lease_owner);

        app(TenantContext::class)->clear();
    }

    public function test_concurrent_acquire_only_one_owner_wins(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $source = $this->seedSource($tenant, 'wordpress');
        $runA = $this->makeRun($tenant, $source, 'posts', 'race-mdb');
        $runB = $this->makeRun($tenant, $source, 'posts', 'race-mdb');

        app(TenantContext::class)->set($tenant);
        $leases = app(SyncLeaseManager::class);
        $leases->ensureScopeRow($runA);

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );
        $userName = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $now = now()->addSeconds(300)->format('Y-m-d H:i:s');
        $ts = now()->format('Y-m-d H:i:s');

        $pdoA = new \PDO($dsn, $userName, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdoB = new \PDO($dsn, $userName, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdoA->beginTransaction();
        $lockA = $pdoA->prepare(
            'SELECT id, sync_run_id, generation FROM sync_active_scopes
             WHERE data_source_id = ? AND entity_type = ? AND scope_key = ?
             FOR UPDATE'
        );
        $lockA->execute([$source->id, 'posts', 'race-mdb']);
        $row = $lockA->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);

        $updateA = $pdoA->prepare(
            'UPDATE sync_active_scopes
             SET sync_run_id = ?, lease_owner = ?, generation = ?, lease_expires_at = ?, heartbeat_at = ?,
                 cancel_requested_at = NULL, cancel_requested_by = NULL, updated_at = ?
             WHERE id = ?'
        );
        $genA = ((int) $row['generation']) + 1;
        $updateA->execute([$runA->id, 'race-a', $genA, $now, $ts, $ts, $row['id']]);
        $pdoA->commit();

        $pdoB->beginTransaction();
        $lockB = $pdoB->prepare(
            'SELECT id, sync_run_id, lease_owner, generation, lease_expires_at FROM sync_active_scopes
             WHERE data_source_id = ? AND entity_type = ? AND scope_key = ?
             FOR UPDATE'
        );
        $lockB->execute([$source->id, 'posts', 'race-mdb']);
        $rowB = $lockB->fetch(\PDO::FETCH_ASSOC);
        $busy = $rowB['sync_run_id'] !== null
            && $rowB['lease_expires_at'] !== null
            && $rowB['lease_expires_at'] > date('Y-m-d H:i:s');
        $this->assertTrue($busy);
        $this->assertSame((int) $runA->id, (int) $rowB['sync_run_id']);
        // Loser does not overwrite.
        $pdoB->rollBack();

        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('scope_key', 'race-mdb')->firstOrFail();
        $this->assertSame('race-a', $scope->lease_owner);
        $this->assertSame($genA, (int) $scope->generation);

        // Second Laravel acquire must conflict while lease is live.
        $this->expectException(\DomainException::class);
        try {
            $leases->acquire($runB, 'race-b');
        } finally {
            app(TenantContext::class)->clear();
        }
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
            'display_name' => $provider.' fence mdb',
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
