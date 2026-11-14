<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\ContentVersion;
use App\Models\DataSource;
use App\Models\MetricObservation;
use App\Models\NormalizationActiveScope;
use App\Models\NormalizationRun;
use App\Models\Site;
use App\Models\Tenant;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Services\NormalizationLeaseManager;
use App\Normalization\Services\NormalizationOrchestrator;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class NormalizationConcurrencyTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        foreach ([
            'normalization_failures',
            'content_source_links',
            'content_versions',
            'content_entities',
            'metric_observations',
            'normalization_active_scopes',
            'normalization_runs',
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

    public function test_duplicate_api_normalize_cannot_create_competing_active_runs(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        Queue::fake();

        $first = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);
        $this->assertSame('queued', $first->json('data.status'));

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(409);

        $this->assertSame(1, NormalizationRun::query()->withoutTenantIsolation()->count());
        $this->assertSame(1, NormalizationActiveScope::query()->withoutTenantIsolation()
            ->whereNotNull('normalization_run_id')->count());
    }

    public function test_stale_worker_fencing_after_ownership_transfer_two_connections(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);

        $runA = NormalizationRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'status' => NormalizationRunStatus::Queued,
            'mapper_version' => '1.0.0',
            'requested_by' => $user->id,
            'is_simulated' => true,
        ]);
        $runB = NormalizationRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'status' => NormalizationRunStatus::Queued,
            'mapper_version' => '1.0.0',
            'requested_by' => $user->id,
            'is_simulated' => true,
        ]);

        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-worker');
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-worker');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );
        $dbUser = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        $pdoStale = new \PDO($dsn, $dbUser, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdoFresh = new \PDO($dsn, $dbUser, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdoStale->beginTransaction();
        $stmtStale = $pdoStale->prepare(
            'SELECT id, normalization_run_id, lease_owner, generation
             FROM normalization_active_scopes
             WHERE data_source_id = ?
             FOR UPDATE'
        );
        $stmtStale->execute([$source->id]);
        $staleView = $stmtStale->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($staleView);

        $freshStarted = false;
        $pdoFresh->beginTransaction();
        try {
            // Fresh blocks until stale rolls back.
            $stmtFresh = $pdoFresh->prepare(
                'SELECT id, normalization_run_id, lease_owner, generation
                 FROM normalization_active_scopes
                 WHERE data_source_id = ?
                 FOR UPDATE'
            );
            // Set a short lock wait then roll back stale so fresh proceeds.
            $pdoFresh->exec('SET innodb_lock_wait_timeout=2');
            $freshStarted = true;
            $pdoStale->rollBack();
            $stmtFresh->execute([$source->id]);
            $freshView = $stmtFresh->fetch(\PDO::FETCH_ASSOC);
            $pdoFresh->commit();

            $this->assertSame((int) $runB->id, (int) $freshView['normalization_run_id']);
            $this->assertSame('fresh-worker', $freshView['lease_owner']);
            $this->assertSame((int) $fresh['generation'], (int) $freshView['generation']);
        } finally {
            if ($pdoStale->inTransaction()) {
                $pdoStale->rollBack();
            }
            if ($pdoFresh->inTransaction()) {
                $pdoFresh->rollBack();
            }
        }

        $this->assertTrue($freshStarted);

        $this->expectException(\DomainException::class);
        $leases->assertOwnershipLocked($runA->fresh(), $stale);
    }

    public function test_sequential_normalize_after_release_does_not_duplicate_versions(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_created', 25);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_unchanged', 25)
            ->assertJsonPath('data.versions_created', 0);

        $this->assertSame(25, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(25, ContentVersion::query()->withoutTenantIsolation()->count());
        $this->assertSame(0, NormalizationActiveScope::query()->withoutTenantIsolation()
            ->whereNotNull('normalization_run_id')->count());
    }

    public function test_metric_upsert_remains_idempotent_under_repeated_normalize(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'google_search_console', 'search_analytics');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.metrics_created', 25);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.metrics_created', 0)
            ->assertJsonPath('data.metrics_updated', 0);

        $this->assertSame(25, MetricObservation::query()->withoutTenantIsolation()->count());
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
            'display_name' => $provider.' concurrency source',
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
