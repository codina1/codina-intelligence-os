<?php

namespace Tests\Feature\Synchronization;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\Site;
use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Services\CheckpointManager;
use App\Synchronization\Services\IngestionService;
use App\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class SyncDatabaseTest extends TestCase
{
    public function test_sync_tables_exist_after_migrations(): void
    {
        foreach ([
            'sync_active_scopes',
            'sync_runs',
            'sync_checkpoints',
            'sync_attempts',
            'ingestion_batches',
            'ingestion_records',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' missing');
        }
    }

    public function test_unique_ingestion_record_constraint(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        app(TenantContext::class)->set($tenant);
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $source = DataSource::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'WP',
            'mode' => 'mock',
            'status' => 'configured',
            'auth_method' => 'none',
            'settings' => ['fixture_dataset' => 'success', 'pagination_size' => 10, 'simulated_error_scenario' => 'none', 'reporting_timezone' => 'UTC'],
        ]);

        IngestionRecord::query()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'external_id' => '1',
            'external_id_hash' => hash('sha256', '1'),
            'payload_hash' => hash('sha256', 'a'),
            'payload_json' => ['id' => 1, 'title' => 'A'],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        try {
            IngestionRecord::query()->create([
                'tenant_id' => $tenant->id,
                'data_source_id' => $source->id,
                'entity_type' => 'posts',
                'external_id' => '1',
                'external_id_hash' => hash('sha256', '1'),
                'payload_hash' => hash('sha256', 'b'),
                'payload_json' => ['id' => 1, 'title' => 'B'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    public function test_atomic_batch_checkpoint_rollback(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        app(TenantContext::class)->set($tenant);
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);
        $source = DataSource::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'WP',
            'mode' => 'mock',
            'status' => 'configured',
            'auth_method' => 'none',
            'settings' => ['fixture_dataset' => 'success', 'pagination_size' => 10, 'simulated_error_scenario' => 'none', 'reporting_timezone' => 'UTC'],
        ]);
        $run = SyncRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'rollback',
            'sync_type' => SyncType::Full,
            'status' => SyncRunStatus::Running,
            'is_simulated' => true,
            'options_json' => [],
        ]);

        $checkpoints = app(CheckpointManager::class);
        $checkpoint = $checkpoints->getOrCreate($run);
        $ingestion = app(IngestionService::class);

        try {
            DB::transaction(function () use ($ingestion, $checkpoints, $run, $checkpoint, $source) {
                $ingestion->processPage(
                    $run,
                    1,
                    [['id' => 99, 'source' => 'wordpress', 'schema_version' => '1.0', 'title' => 'Rollback me']],
                    ['page' => 1],
                    ['page' => 2],
                    $source->provider,
                    '1.0',
                );
                $checkpoints->commitPage($run, $checkpoint, 1, ['page' => 2]);
                throw new \RuntimeException('force_rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, IngestionRecord::query()->withoutTenantIsolation()->where('external_id', '99')->count());
        $this->assertSame(0, (int) SyncCheckpoint::query()->withoutTenantIsolation()->whereKey($checkpoint->id)->value('last_completed_page'));
        app(TenantContext::class)->clear();
    }

    public function test_tenant_aware_foreign_keys_reject_cross_tenant_data_source(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        app(TenantContext::class)->set($tenantA);
        $site = Site::factory()->create(['tenant_id' => $tenantA->id]);
        $source = DataSource::query()->create([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'WP',
            'mode' => 'mock',
            'status' => 'configured',
            'auth_method' => 'none',
            'settings' => ['fixture_dataset' => 'success', 'pagination_size' => 10, 'simulated_error_scenario' => 'none', 'reporting_timezone' => 'UTC'],
        ]);
        app(TenantContext::class)->clear();

        $this->expectException(QueryException::class);
        DB::table('sync_runs')->insert([
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenantB->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'default',
            'sync_type' => 'full',
            'status' => 'queued',
            'is_simulated' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
