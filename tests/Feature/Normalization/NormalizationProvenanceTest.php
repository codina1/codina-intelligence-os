<?php

namespace Tests\Feature\Normalization;

use App\Models\ContentEntity;
use App\Models\ContentVersion;
use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Models\NormalizationFailure;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Synchronization\Enums\SyncRunStatus;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class NormalizationProvenanceTest extends TestCase
{
    public function test_completed_then_partial_does_not_project_inadmissible_updates(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_created', 25);

        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();
        $completedRun = SyncRun::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->where('status', SyncRunStatus::Completed->value)
            ->firstOrFail();

        $partial = SyncRun::query()->withoutTenantIsolation()->create([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'partial-overwrite',
            'sync_type' => 'full',
            'status' => SyncRunStatus::Partial->value,
            'is_simulated' => true,
        ]);

        $record = IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->where('external_id', '1')
            ->firstOrFail();
        $payload = $record->payload_json;
        $payload['title'] = 'Partial overwrite title';
        $record->payload_json = $payload;
        $record->payload_hash = hash('sha256', json_encode($payload));
        $record->last_sync_run_id = $partial->id;
        $record->save();

        $beforeVersions = ContentVersion::query()->withoutTenantIsolation()->count();
        $beforeTitle = ContentEntity::query()->withoutTenantIsolation()
            ->where('external_id', '1')->value('title');

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame(0, $response->json('data.entities_updated'));
        $this->assertSame(0, $response->json('data.versions_created'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.records_rejected'));
        $this->assertSame($beforeVersions, ContentVersion::query()->withoutTenantIsolation()->count());
        $this->assertSame($beforeTitle, ContentEntity::query()->withoutTenantIsolation()
            ->where('external_id', '1')->value('title'));
        $this->assertNotSame($completedRun->id, $record->fresh()->last_sync_run_id);

        $failure = NormalizationFailure::query()->withoutTenantIsolation()
            ->where('error_code', NormalizationErrorCode::InadmissibleProvenance->value)
            ->first();
        $this->assertNotNull($failure);
        $this->assertSame('prior_completed_snapshot_not_reconstructable', $failure->safe_diagnostics_json['limitation'] ?? null);
    }

    public function test_completed_then_failed_and_new_partial_record_are_rejected(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        $failed = SyncRun::query()->withoutTenantIsolation()->create([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'failed-run',
            'sync_type' => 'full',
            'status' => SyncRunStatus::Failed->value,
            'is_simulated' => true,
        ]);

        IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->update(['last_sync_run_id' => $failed->id]);

        $partial = SyncRun::query()->withoutTenantIsolation()->create([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'partial-new',
            'sync_type' => 'full',
            'status' => SyncRunStatus::Partial->value,
            'is_simulated' => true,
        ]);

        IngestionRecord::query()->withoutTenantIsolation()->create([
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'external_id' => 'partial-only-99',
            'external_id_hash' => hash('sha256', 'partial-only-99'),
            'payload_hash' => hash('sha256', 'partial-only'),
            'payload_json' => [
                'id' => 99,
                'source' => 'wordpress',
                'schema_version' => '1.0',
                'title' => 'Only from partial',
                'status' => 'publish',
            ],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_sync_run_id' => $partial->id,
        ]);

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame(0, $response->json('data.entities_created'));
        $this->assertSame(0, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertGreaterThanOrEqual(26, $response->json('data.records_rejected'));
    }

    public function test_later_successful_retry_makes_records_admissible_again(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        $partial = SyncRun::query()->withoutTenantIsolation()->create([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'then-retry',
            'sync_type' => 'full',
            'status' => SyncRunStatus::Partial->value,
            'is_simulated' => true,
        ]);
        IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->update(['last_sync_run_id' => $partial->id]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_created', 0);

        // Successful retry rewrites provenance to a completed run.
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'retry-complete',
        ])->assertStatus(202)->assertJsonPath('data.status', 'completed');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.entities_created', 25);

        $this->assertSame(25, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertSame(25, ContentVersion::query()->withoutTenantIsolation()->count());
    }

    public function test_historical_completed_existence_alone_is_insufficient_without_admissible_rows(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($user, $tenant, 'wordpress', 'posts');
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $sourceId)->firstOrFail();

        $this->assertTrue(
            SyncRun::query()->withoutTenantIsolation()
                ->where('data_source_id', $source->id)
                ->where('status', SyncRunStatus::Completed->value)
                ->exists()
        );

        $partial = SyncRun::query()->withoutTenantIsolation()->create([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenant->id,
            'data_source_id' => $source->id,
            'entity_type' => 'posts',
            'scope_key' => 'poison',
            'sync_type' => 'full',
            'status' => SyncRunStatus::Partial->value,
            'is_simulated' => true,
        ]);
        IngestionRecord::query()->withoutTenantIsolation()
            ->where('data_source_id', $source->id)
            ->update(['last_sync_run_id' => $partial->id]);

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $this->assertSame('completed_with_errors', $response->json('data.status'));
        $this->assertSame(25, $response->json('data.records_rejected'));
        $this->assertSame(0, $response->json('data.entities_created'));
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
            'display_name' => $provider.' provenance source',
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
