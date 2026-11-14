<?php

namespace Tests\Feature\Normalization;

use App\Connectors\Enums\DataSourceStatus;
use App\Models\AuditLog;
use App\Models\ContentEntity;
use App\Models\DataSource;
use App\Models\NormalizationRun;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use App\Normalization\Jobs\NormalizationRunJob;
use App\Normalization\Services\NormalizationLeaseManager;
use App\Normalization\Services\NormalizationOrchestrator;
use App\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class NormalizationAuthExecutionTest extends TestCase
{
    public function test_permission_revoked_after_queue_denies_execution(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');

        Queue::fake();
        $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);

        $run = NormalizationRun::query()->withoutTenantIsolation()->firstOrFail();
        $viewerRole = Role::query()->where('slug', 'viewer')->firstOrFail();
        TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $owner->id)
            ->update(['role_id' => $viewerRole->id]);

        $job = new NormalizationRunJob((int) $run->id, (int) $tenant->id, (int) $owner->id);
        $job->handle(app(TenantContext::class), app(NormalizationOrchestrator::class));

        $run->refresh();
        $this->assertSame(NormalizationRunStatus::Failed, $run->status);
        $this->assertSame(NormalizationErrorCode::UnauthorizedExecution->value, $run->last_error_code);
        $this->assertSame(0, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'normalization.execution_denied')
                ->exists()
        );
    }

    public function test_user_removed_from_tenant_denies_execution(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');

        Queue::fake();
        $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);
        $run = NormalizationRun::query()->withoutTenantIsolation()->firstOrFail();

        TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $owner->id)
            ->delete();

        (new NormalizationRunJob((int) $run->id, (int) $tenant->id, (int) $owner->id))
            ->handle(app(TenantContext::class), app(NormalizationOrchestrator::class));

        $run->refresh();
        $this->assertSame(NormalizationRunStatus::Failed, $run->status);
        $this->assertSame(NormalizationErrorCode::UnauthorizedExecution->value, $run->last_error_code);
    }

    public function test_source_disabled_before_execution_fails_safely(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');

        Queue::fake();
        $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);
        $run = NormalizationRun::query()->withoutTenantIsolation()->firstOrFail();

        DataSource::query()->withoutTenantIsolation()
            ->where('public_id', $sourceId)
            ->update(['status' => DataSourceStatus::Disabled->value]);

        (new NormalizationRunJob((int) $run->id, (int) $tenant->id, (int) $owner->id))
            ->handle(app(TenantContext::class), app(NormalizationOrchestrator::class));

        $run->refresh();
        $this->assertSame(NormalizationRunStatus::Failed, $run->status);
        $this->assertSame(NormalizationErrorCode::SourceDisabled->value, $run->last_error_code);
        $this->assertSame(0, ContentEntity::query()->withoutTenantIsolation()->count());
    }

    public function test_forged_run_tenant_identifiers_are_denied(): void
    {
        [$ownerA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$ownerB, $tenantB] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($ownerA, $tenantA, 'wordpress', 'posts');

        Queue::fake();
        $this->actingAs($ownerA)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202);
        $run = NormalizationRun::query()->withoutTenantIsolation()->firstOrFail();

        (new NormalizationRunJob((int) $run->id, (int) $tenantB->id, (int) $ownerB->id))
            ->handle(app(TenantContext::class), app(NormalizationOrchestrator::class));

        $run->refresh();
        // Run belongs to tenant A; forged tenant B does not execute projections.
        $this->assertSame(0, ContentEntity::query()->withoutTenantIsolation()->count());
        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'normalization.unauthorized_execution')
                ->exists()
        );
    }

    public function test_valid_permissions_still_execute_normally(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createAndSync($owner, $tenant, 'wordpress', 'posts');

        $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/normalize')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.entities_created', 25);

        $this->assertNull(app(TenantContext::class)->id());
        $this->assertSame(0, app(NormalizationLeaseManager::class) instanceof NormalizationLeaseManager
            ? \App\Models\NormalizationActiveScope::query()->withoutTenantIsolation()
                ->whereNotNull('normalization_run_id')->count()
            : 0);
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
            'display_name' => $provider.' auth source',
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
