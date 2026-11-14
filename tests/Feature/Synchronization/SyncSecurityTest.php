<?php

namespace Tests\Feature\Synchronization;

use App\Models\IngestionRecord;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('critical')]
class SyncSecurityTest extends TestCase
{
    public function test_cross_tenant_sync_access_denied_with_non_enumeration(): void
    {
        [$ownerA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$ownerB] = $this->makeTenantUser('tenant_owner');

        $sourceId = $this->createSyncSource($ownerA, $tenantA, 'wordpress');

        $this->actingAs($ownerB)->getJson('/api/v1/data-sources/'.$sourceId)
            ->assertNotFound();

        $this->actingAs($ownerB)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
        ])->assertNotFound();

        $this->actingAs($ownerB)->getJson('/api/v1/data-sources/'.str()->uuid())
            ->assertNotFound();
    }

    public function test_csrf_enforced_on_sync_mutations(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress');

        $this->actingAs($user)
            ->withSession(['_token' => 'expected-token'])
            ->withHeader('X-CSRF-TOKEN', 'wrong-token')
            ->postJson('/api/v1/data-sources/'.$sourceId.'/sync', [
                'sync_type' => 'full',
                'entity_type' => 'posts',
            ])
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'csrf_token_mismatch');
    }

    public function test_viewer_cannot_start_cancel_or_retry(): void
    {
        [$owner, $tenant] = $this->makeTenantUser('tenant_owner');
        [$viewer] = $this->makeTenantUser('viewer', $tenant);
        $sourceId = $this->createSyncSource($owner, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 25,
        ]);

        $this->actingAs($viewer)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
        ])->assertForbidden();

        $runId = $this->actingAs($owner)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
        ])->assertStatus(202)->json('data.id');

        $this->actingAs($viewer)->postJsonWithCsrf('/api/v1/sync-runs/'.$runId.'/cancel')
            ->assertForbidden();
        $this->actingAs($viewer)->postJsonWithCsrf('/api/v1/sync-runs/'.$runId.'/retry')
            ->assertForbidden();

        $this->actingAs($viewer)->getJson('/api/v1/sync-runs/'.$runId)
            ->assertOk();
    }

    public function test_no_plaintext_secrets_in_staging_payloads(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'invalid_schema',
            'pagination_size' => 10,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'scope_key' => 'safe',
        ])->assertStatus(202);

        $payloads = IngestionRecord::query()->withoutTenantIsolation()->pluck('payload_json');
        foreach ($payloads as $payload) {
            $encoded = json_encode($payload);
            $this->assertStringNotContainsString('should-reject', (string) $encoded);
            $this->assertArrayNotHasKey('api_token', $payload);
        }
    }

    public function test_public_api_rejects_lease_overrides_and_internal_options(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $sourceId = $this->createSyncSource($user, $tenant, 'wordpress', [
            'fixture_dataset' => 'paginated_25',
            'pagination_size' => 25,
        ]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
            'options' => [
                'lease_owner' => 'attacker',
                'generation' => 999,
                'tenant_id' => 12345,
                'fencing_token' => 'nope',
            ],
        ])->assertStatus(422);

        $ok = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$sourceId.'/sync', [
            'sync_type' => 'full',
            'entity_type' => 'posts',
        ])->assertStatus(202);

        $this->assertSame('completed', $ok->json('data.status'));
        $this->assertArrayNotHasKey('lease_owner', $ok->json('data'));
        $this->assertArrayNotHasKey('generation', $ok->json('data'));
    }

    public function test_http_prevent_stray_requests_during_security_suite(): void
    {
        Http::preventStrayRequests();
        $this->assertFalse(config('codina.allow_external_http'));
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
            'display_name' => $provider.' security source',
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
