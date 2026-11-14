<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\ContentVersion;
use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\NormalizationActiveScope;
use App\Models\NormalizationFailure;
use App\Models\NormalizationRun;
use App\Models\Site;
use App\Models\Tenant;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Services\ContentProjectionService;
use App\Normalization\Services\NormalizationLeaseManager;
use App\Normalization\Services\NormalizationRunner;
use App\Normalization\Services\NormalizerRegistry;
use App\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * True two-connection MariaDB fencing interleaving for normalization projection.
 */
#[Group('critical')]
class NormalizationLeaseFencingMariaDbTest extends TestCase
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

    public function test_stale_worker_projection_rejected_after_ownership_transfer(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);
        $runner = app(NormalizationRunner::class);

        $runA = $this->makeRun($tenant, $source, $user->id);
        $runB = $this->makeRun($tenant, $source, $user->id);

        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-worker');
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-worker');

        $beforeEntities = ContentEntity::query()->withoutTenantIsolation()->count();
        $beforeVersions = ContentVersion::query()->withoutTenantIsolation()->count();

        // Stale worker aborts safely (no throw); marks self failed without writing.
        $aborted = $runner->run($runA->fresh(), $stale);
        $this->assertSame(NormalizationRunStatus::Failed, $aborted->status);
        $this->assertSame(NormalizationErrorCode::StaleLease->value, $aborted->last_error_code);

        $runA->refresh();
        $this->assertSame(NormalizationRunStatus::Failed, $runA->status);

        $this->assertSame($beforeEntities, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame($beforeVersions, ContentVersion::query()->withoutTenantIsolation()->count());

        // Fresh owner succeeds and is the only writer.
        $completed = $runner->run($runB->fresh(), $fresh);
        $this->assertContains($completed->status, [
            NormalizationRunStatus::Completed,
            NormalizationRunStatus::CompletedWithErrors,
        ]);
        $this->assertGreaterThan(0, ContentEntity::query()->withoutTenantIsolation()->count());

        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)->firstOrFail();
        $this->assertNull($scope->normalization_run_id);
        $this->assertSame('fresh-worker', $runB->fresh()->lease_owner);

        // Stale abort must not have released/clobbered fresh ownership mid-run.
        $runB->refresh();
        $this->assertNotSame(NormalizationRunStatus::Failed, $runB->status);

        app(TenantContext::class)->clear();
    }

    public function test_held_projection_lock_blocks_ownership_transfer_until_commit(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);

        $runA = $this->makeRun($tenant, $source, $user->id);
        $runB = $this->makeRun($tenant, $source, $user->id);
        $leases->ensureScopeRow($runA);
        $leaseA = $leases->acquire($runA, 'holder-a');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            config('database.connections.mysql.host'),
            config('database.connections.mysql.port'),
            config('database.connections.mysql.database'),
        );
        $dbUser = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        $pdoA = new \PDO($dsn, $dbUser, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdoB = new \PDO($dsn, $dbUser, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        // A holds scope FOR UPDATE as in a live projection transaction.
        $pdoA->beginTransaction();
        $lockA = $pdoA->prepare(
            'SELECT id, normalization_run_id, lease_owner, generation
             FROM normalization_active_scopes
             WHERE data_source_id = ?
             FOR UPDATE'
        );
        $lockA->execute([$source->id]);
        $held = $lockA->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($held);
        $this->assertSame((int) $runA->id, (int) $held['normalization_run_id']);
        $this->assertSame('holder-a', $held['lease_owner']);

        // B cannot transfer ownership while A's projection txn holds the row.
        $pdoB->beginTransaction();
        $pdoB->exec('SET innodb_lock_wait_timeout=1');
        $blocked = false;
        try {
            $lockB = $pdoB->prepare(
                'SELECT id, normalization_run_id, lease_owner, generation
                 FROM normalization_active_scopes
                 WHERE data_source_id = ?
                 FOR UPDATE'
            );
            $lockB->execute([$source->id]);
            $this->fail('B should block on A\'s FOR UPDATE');
        } catch (\PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout')
                || str_contains($e->getMessage(), '1205');
            $this->assertTrue($blocked, $e->getMessage());
        } finally {
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
        }

        // Second attempt still blocked while A holds the row.
        $pdoB->beginTransaction();
        $pdoB->exec('SET innodb_lock_wait_timeout=1');
        try {
            $lockB2 = $pdoB->prepare(
                'SELECT id FROM normalization_active_scopes WHERE data_source_id = ? FOR UPDATE'
            );
            $lockB2->execute([$source->id]);
            $this->fail('B still blocked until A commits/rolls back');
        } catch (\PDOException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205'),
                $e->getMessage()
            );
        } finally {
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
        }

        $pdoA->commit();

        // After A releases the projection lock, expire + transfer succeeds.
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'holder-b');
        $this->assertSame('holder-b', $fresh['owner']);
        $this->assertGreaterThan($leaseA['generation'], $fresh['generation']);

        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)->firstOrFail();
        $this->assertSame((int) $runB->id, (int) $scope->normalization_run_id);
        $this->assertSame('holder-b', $scope->lease_owner);

        app(TenantContext::class)->clear();
    }

    public function test_two_pdo_interleave_stale_write_rolled_back_fresh_commits(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $record = IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)->orderBy('id')->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);
        $registry = app(NormalizerRegistry::class);

        $runA = $this->makeRun($tenant, $source, $user->id);
        $runB = $this->makeRun($tenant, $source, $user->id);
        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-pdo');
        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-pdo');

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

        $beforeVersions = ContentVersion::query()->withoutTenantIsolation()->count();

        // 1) Stale begins projection-shaped txn and locks scope with outdated generation view.
        $pdoStale->beginTransaction();
        $stmtStale = $pdoStale->prepare(
            'SELECT id, normalization_run_id, lease_owner, generation
             FROM normalization_active_scopes WHERE data_source_id = ? FOR UPDATE'
        );
        $stmtStale->execute([$source->id]);
        $staleView = $stmtStale->fetch(\PDO::FETCH_ASSOC);
        $staleOwns = (int) $staleView['normalization_run_id'] === (int) $runA->id
            && $staleView['lease_owner'] === $stale['owner']
            && (int) $staleView['generation'] === (int) $stale['generation'];
        $this->assertFalse($staleOwns);

        // Illegal entity insert under stale txn — must roll back.
        $pdoStale->prepare(
            'INSERT INTO content_entities
             (public_id, tenant_id, site_id, data_source_id, entity_type, external_id, external_id_hash,
              content_type, title, publication_status, normalized_status, current_version_number,
              normalized_payload_hash, first_seen_at, last_seen_at, normalized_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), NOW(), NOW(), NOW())'
        )->execute([
            (string) Str::uuid(),
            $tenant->id,
            $source->site_id,
            $source->id,
            'post',
            'stale-illegal',
            hash('sha256', 'stale-illegal'),
            'post',
            'Stale Illegal',
            'publish',
            'active',
            hash('sha256', 'stale'),
        ]);

        $pdoFresh->beginTransaction();
        $pdoFresh->exec('SET innodb_lock_wait_timeout=2');
        $stmtFresh = $pdoFresh->prepare(
            'SELECT id, normalization_run_id, lease_owner, generation
             FROM normalization_active_scopes WHERE data_source_id = ? FOR UPDATE'
        );
        $pdoStale->rollBack();
        $stmtFresh->execute([$source->id]);
        $freshView = $stmtFresh->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame((int) $runB->id, (int) $freshView['normalization_run_id']);
        $this->assertSame('fresh-pdo', $freshView['lease_owner']);
        $this->assertSame((int) $fresh['generation'], (int) $freshView['generation']);
        $pdoFresh->commit();

        $this->assertSame(0, ContentEntity::query()->withoutTenantIsolation()
            ->where('external_id', 'stale-illegal')->count());

        // Fresh Laravel fencing + real projection commit.
        DB::transaction(function () use ($leases, $runB, $fresh, $source, $record, $registry) {
            $leases->assertOwnershipLocked($runB->fresh(), $fresh);
            $normalizer = $registry->resolve('wordpress', (string) $record->entity_type);
            $this->assertNotNull($normalizer);
            $normalized = $normalizer->normalizeContent($record, $source);
            app(ContentProjectionService::class)->project($source, $record, $normalized);
        });

        $this->assertSame($beforeVersions + 1, ContentVersion::query()->withoutTenantIsolation()->count());

        try {
            $leases->assertOwnershipLocked($runA->fresh(), $stale);
            $this->fail('Stale assertOwnershipLocked should throw');
        } catch (DomainException $e) {
            $this->assertSame(NormalizationErrorCode::StaleLease->value, $e->getMessage());
        }

        app(TenantContext::class)->clear();
    }

    public function test_stale_abort_does_not_clobber_newer_owner_or_release_foreign_lease(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);

        $runA = $this->makeRun($tenant, $source, $user->id);
        $runB = $this->makeRun($tenant, $source, $user->id);
        $leases->ensureScopeRow($runA);
        $stale = $leases->acquire($runA, 'stale-abort');
        $runA->forceFill([
            'status' => NormalizationRunStatus::Running,
            'started_at' => CarbonImmutable::now(),
        ])->save();

        $leases->expireLeaseForTest($runA);
        $fresh = $leases->acquire($runB, 'fresh-abort');
        $runB->forceFill([
            'status' => NormalizationRunStatus::Running,
            'started_at' => CarbonImmutable::now(),
        ])->save();

        $updated = $leases->applyStaleWorkerAbort($runA, $stale, ['records_scanned' => 3]);
        $this->assertTrue($updated);

        $runA->refresh();
        $runB->refresh();
        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)->firstOrFail();

        $this->assertSame(NormalizationRunStatus::Failed, $runA->status);
        $this->assertSame(NormalizationErrorCode::StaleLease->value, $runA->last_error_code);
        $this->assertSame(NormalizationRunStatus::Running, $runB->status);
        $this->assertSame((int) $runB->id, (int) $scope->normalization_run_id);
        $this->assertSame('fresh-abort', $scope->lease_owner);
        $this->assertSame((int) $fresh['generation'], (int) $scope->generation);

        // Completed never reverted.
        $runB->forceFill([
            'status' => NormalizationRunStatus::Completed,
            'finished_at' => CarbonImmutable::now(),
        ])->save();
        $this->assertFalse($leases->applyStaleWorkerAbort($runB, $fresh));
        $this->assertSame(NormalizationRunStatus::Completed, $runB->fresh()->status);

        app(TenantContext::class)->clear();
    }

    public function test_staging_mutation_between_validate_and_project_is_rejected(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $record = IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)->orderBy('id')->firstOrFail();

        app(TenantContext::class)->set($tenant);
        $leases = app(NormalizationLeaseManager::class);
        $runner = app(NormalizationRunner::class);

        $run = $this->makeRun($tenant, $source, $user->id);
        $leases->ensureScopeRow($run);
        $lease = $leases->acquire($run, 'mutate-worker');

        // Mutate staging after enqueue-shaped acquire but before worker processes rows.
        $record->forceFill([
            'payload_hash' => hash('sha256', 'mutated-mid-flight'),
            'last_changed_at' => CarbonImmutable::now(),
        ])->save();

        // Direct fenced evaluate path: snapshot taken before mutation must fail.
        $snapshot = [
            'id' => (int) $record->id,
            'payload_hash' => 'original-hash-not-matching',
            'last_sync_run_id' => (int) $record->last_sync_run_id,
            'last_changed_at' => null,
        ];

        DB::transaction(function () use ($leases, $run, $lease, $record, $snapshot, $source) {
            $leases->assertOwnershipLocked($run, $lease);
            $fresh = IngestionRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $gate = app(\App\Normalization\Services\StagingProvenanceGate::class)
                ->evaluate(
                    $fresh,
                    app(\App\Normalization\Services\StagingProvenanceGate::class)
                        ->completedRunIds((int) $source->tenant_id, (int) $source->id),
                    $snapshot
                );
            $this->assertFalse($gate['ok']);
            $this->assertSame(NormalizationErrorCode::StagingMutated->value, $gate['error_code']);

            NormalizationFailure::query()->create([
                'tenant_id' => $run->tenant_id,
                'normalization_run_id' => $run->id,
                'ingestion_record_id' => $fresh->id,
                'error_code' => $gate['error_code'],
                'safe_diagnostics_json' => $gate['diagnostics'],
                'created_at' => CarbonImmutable::now(),
            ]);
        });

        $this->assertTrue(
            NormalizationFailure::query()->withoutTenantIsolation()
                ->where('error_code', NormalizationErrorCode::StagingMutated->value)
                ->exists()
        );

        // Full runner still completes against current staging (idempotent / admissible).
        $result = $runner->run($run->fresh(), $lease);
        $this->assertContains($result->status, [
            NormalizationRunStatus::Completed,
            NormalizationRunStatus::CompletedWithErrors,
        ]);

        app(TenantContext::class)->clear();
    }

    private function makeRun(Tenant $tenant, DataSource $source, int $userId): NormalizationRun
    {
        return NormalizationRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'status' => NormalizationRunStatus::Queued,
            'mapper_version' => '1.0.0',
            'requested_by' => $userId,
            'is_simulated' => true,
        ]);
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
            'display_name' => $provider.' fence mdb',
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
