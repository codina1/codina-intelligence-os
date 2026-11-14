<?php

namespace Tests\Feature\Connectors;

use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\DataSourceConfigurationVersion;
use App\Models\DataSourceHealthCheck;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class DataSourceRegistryTest extends TestCase
{
    public function test_all_five_providers_are_listed(): void
    {
        [$user] = $this->makeTenantUser('viewer');

        $keys = collect(
            $this->actingAs($user)->getJson('/api/v1/connector-providers')->assertOk()->json('data')
        )->pluck('key')->sort()->values()->all();

        $this->assertSame([
            'google_analytics_4',
            'google_search_console',
            'tutor_lms_pro',
            'woocommerce',
            'wordpress',
        ], $keys);

        foreach ($keys as $key) {
            $this->actingAs($user)
                ->getJson('/api/v1/connector-providers/'.$key)
                ->assertOk()
                ->assertJsonPath('data.mock_only', true)
                ->assertJsonPath('data.mode', 'mock');
        }
    }

    public function test_create_valid_mock_sources_for_each_provider(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['wordpress', 'woocommerce', 'google_search_console', 'google_analytics_4', 'tutor_lms_pro'] as $provider) {
            $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
                'tenant_id' => $tenant->id,
                'site_id' => $site->id,
                'provider' => $provider,
                'display_name' => ucfirst(str_replace('_', ' ', $provider)).' Mock',
                'mode' => 'mock',
                'auth_method' => 'none',
                'settings' => [
                    'fixture_dataset' => 'success',
                    'pagination_size' => 5,
                    'reporting_timezone' => 'UTC',
                    'mock_external_resource_id' => 'mock-'.$provider,
                ],
            ])->assertCreated();

            $this->assertSame('configured', $response->json('data.status'));
            $this->assertSame('mock', $response->json('data.mode'));
            $this->assertNotEmpty($response->json('data.capabilities'));
            $this->assertSame(1, DataSourceConfigurationVersion::query()->withoutTenantIsolation()
                ->where('data_source_id', $response->json('data.internal_id'))->count());
        }
    }

    public function test_provider_specific_config_validation_rejects_unsafe_settings(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Bad Settings',
            'settings' => [
                'api_token' => 'super-secret-token-value-0123456789abcdef',
                'site_url' => 'https://example.com',
            ],
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Unknown Key',
            'settings' => [
                'unexpected_field' => 'nope',
            ],
        ])->assertStatus(422);
    }

    public function test_update_and_disable_lifecycle(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
            'display_name' => 'Updated WP',
            'settings' => [
                'fixture_dataset' => 'empty',
                'pagination_size' => 20,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'Europe/Berlin',
            ],
            'change_reason' => 'tune_mock',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Updated WP')
            ->assertJsonPath('data.settings.pagination_size', 20);

        $this->assertGreaterThanOrEqual(
            2,
            DataSourceConfigurationVersion::query()->withoutTenantIsolation()->count()
        );

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/disable')
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertTrue(
            AuditLog::query()->where('action', 'data_source.disabled')->exists()
        );
    }

    public function test_invalid_state_transition_rejected(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/disable')->assertOk();

        // disabled -> healthy is not allowed; must re-enable via configured
        $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
            'status' => 'healthy',
        ])->assertStatus(422);
    }

    public function test_duplicate_registration_rejected(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        $payload = [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => 'wordpress',
            'display_name' => 'WP One',
            'mode' => 'mock',
        ];

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', $payload)->assertCreated();
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', $payload)->assertStatus(409);
    }

    public function test_mock_test_success_and_failure_scenarios(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test', [
            'scenario' => 'success',
        ])->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.result.verified_real_connection', false)
            ->assertJsonPath('data.result.latency_is_simulated', true)
            ->assertJsonPath('data.data_source.status', 'healthy');

        $successAt = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->value('last_success_at');
        $this->assertNotNull($successAt);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test', [
            'scenario' => 'unauthorized',
        ])->assertOk()
            ->assertJsonPath('data.result.ok', false)
            ->assertJsonPath('data.data_source.status', 'error');

        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->firstOrFail();
        $this->assertNotNull($source->last_success_at);
        $this->assertEquals($successAt->timestamp, $source->last_success_at->timestamp);

        $history = $this->actingAs($user)->getJson('/api/v1/data-sources/'.$id.'/health')->assertOk();
        $this->assertGreaterThanOrEqual(2, $history->json('meta.pagination.total'));
    }

    public function test_disabled_source_cannot_execute_tests(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);
        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/disable')->assertOk();

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test')
            ->assertStatus(422);
    }

    public function test_filtering_and_capabilities_endpoints(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $this->createSource($user, $tenant, 'wordpress');
        $this->createSource($user, $tenant, 'woocommerce');

        $this->actingAs($user)
            ->getJson('/api/v1/data-sources?provider=wordpress&status=configured&mode=mock')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $id = DataSource::query()->withoutTenantIsolation()->where('provider', 'wordpress')->value('public_id');
        $this->actingAs($user)
            ->getJson('/api/v1/data-sources/'.$id.'/capabilities')
            ->assertOk()
            ->assertJsonFragment(['capability_key' => 'connection_test']);
    }

    #[Group('critical')]
    public function test_no_external_network_during_registry_operations(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        foreach (['success', 'empty', 'unauthorized', 'rate_limit', 'timeout', 'invalid_payload', 'temporary_failure'] as $scenario) {
            if ($scenario !== 'success' && $scenario !== 'empty') {
                // Re-enable path after errors stays executable while not disabled.
                $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->first();
                if ($source->status->value === 'disabled') {
                    $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
                        'status' => 'configured',
                    ])->assertOk();
                }
            }

            $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test', [
                'scenario' => $scenario,
            ])->assertOk();
        }

        Http::assertNothingSent();
        $this->assertFalse((bool) config('codina.allow_external_http'));
        $this->assertSame(0, DataSourceHealthCheck::query()->withoutTenantIsolation()
            ->where('result_metadata->verified_real_connection', true)->count());
    }

    public function test_composite_foreign_key_rejects_cross_tenant_site(): void
    {
        [$user, $tenantA] = $this->makeTenantUser('tenant_owner');
        $tenantB = Tenant::factory()->create();
        $foreignSite = Site::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenantA->id,
            'site_id' => $foreignSite->id,
            'provider' => 'wordpress',
            'display_name' => 'Cross Tenant',
        ])->assertStatus(422);

        // DB-level composite FK also rejects mismatched tenant/site pairs.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('data_sources')->insert([
            'public_id' => (string) str()->uuid(),
            'tenant_id' => $tenantA->id,
            'site_id' => $foreignSite->id,
            'provider' => 'wordpress',
            'display_name' => 'Direct Insert',
            'mode' => 'mock',
            'status' => 'draft',
            'settings' => json_encode(['fixture_dataset' => 'success']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSource($user, Tenant $tenant, string $provider = 'wordpress'): string
    {
        // Distinct site scopes avoid unique(tenant, provider, site_scope) collisions.
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        return $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => $provider,
            'display_name' => $provider.' source',
            'mode' => 'mock',
            'auth_method' => 'none',
            'settings' => [
                'fixture_dataset' => 'success',
                'pagination_size' => 10,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
            ],
        ])->assertCreated()->json('data.id');
    }
}
