<?php

namespace Tests\Feature\Connectors;

use App\Connectors\Services\DataSourceService;
use App\Models\DataSource;
use App\Models\DataSourceHealthCheck;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('critical')]
class DataSourceRemediationTest extends TestCase
{
    public function test_external_resource_id_accepts_mock_safe_canonical_values(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $response = $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Canonical ID',
            'external_resource_id' => 'mock-wp-site-1',
            'settings' => [
                'fixture_dataset' => 'success',
                'pagination_size' => 10,
                'reporting_timezone' => 'UTC',
            ],
        ])->assertCreated();

        $this->assertSame('mock-wp-site-1', $response->json('data.external_resource_id'));
        $this->assertSame('mock-wp-site-1', $response->json('data.settings.mock_external_resource_id'));
    }

    public function test_external_resource_id_rejects_urls_and_credential_like_values(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Bad URL',
            'external_resource_id' => 'https://example.com/wp-json',
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'woocommerce',
            'display_name' => 'Bad Token',
            'external_resource_id' => 'Bearer super-secret-token-value-0123456789',
        ])->assertStatus(422);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'google_analytics_4',
            'display_name' => 'Bad Settings ID',
            'settings' => [
                'mock_external_resource_id' => 'https://analytics.google.com/property/1',
            ],
        ])->assertStatus(422);

        $body = json_encode(
            $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
                'tenant_id' => $tenant->id,
                'provider' => 'wordpress',
                'display_name' => 'Secret Leak Check',
                'external_resource_id' => 'sk-live-abcdefghijklmnopqrstuvwxyz0123456789',
            ])->assertStatus(422)->json()
        );
        $this->assertStringNotContainsString('sk-live-', (string) $body);
    }

    public function test_conflicting_external_resource_identifiers_rejected(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'wordpress',
            'display_name' => 'Conflict',
            'external_resource_id' => 'mock-a',
            'settings' => [
                'mock_external_resource_id' => 'mock-b',
            ],
        ])->assertStatus(422);
    }

    public function test_matching_top_level_and_settings_external_ids_accepted(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'provider' => 'tutor_lms_pro',
            'display_name' => 'Matched IDs',
            'external_resource_id' => 'mock-lms-1',
            'settings' => [
                'mock_external_resource_id' => 'mock-lms-1',
                'fixture_dataset' => 'success',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.external_resource_id', 'mock-lms-1');
    }

    public function test_public_patch_cannot_set_healthy_or_error(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
            'status' => 'healthy',
        ])->assertStatus(422);

        $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
            'status' => 'error',
        ])->assertStatus(422);

        $status = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->value('status');
        $this->assertSame('configured', $status instanceof \BackedEnum ? $status->value : (string) $status);
    }

    public function test_reenable_to_configured_does_not_imply_connectivity(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/disable')->assertOk();
        $response = $this->actingAs($user)->patchJsonWithCsrf('/api/v1/data-sources/'.$id, [
            'status' => 'configured',
        ])->assertOk();

        $this->assertSame('configured', $response->json('data.status'));
        $this->assertNull($response->json('data.last_tested_at'));
        $this->assertNull($response->json('data.last_success_at'));
    }

    public function test_mock_health_check_may_set_healthy_and_error(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test', [
            'scenario' => 'success',
        ])->assertOk()->assertJsonPath('data.data_source.status', 'healthy');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources/'.$id.'/test', [
            'scenario' => 'rate_limit',
        ])->assertOk()->assertJsonPath('data.data_source.status', 'error');
    }

    public function test_health_check_state_update_rolls_back_atomically(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $id = $this->createSource($user, $tenant);
        $source = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->firstOrFail();

        DataSource::updating(function () {
            throw new RuntimeException('forced failure during health persistence');
        });

        $thrown = false;
        try {
            app(DataSourceService::class)->testConnection($user, $id, 'success');
        } catch (RuntimeException $e) {
            $thrown = str_contains($e->getMessage(), 'forced failure');
        } finally {
            DataSource::flushEventListeners();
            DataSource::clearBootedModels();
        }

        $this->assertTrue($thrown, 'Expected forced persistence failure.');

        $fresh = DataSource::query()->withoutTenantIsolation()->where('public_id', $id)->firstOrFail();
        $this->assertSame('configured', $fresh->status->value);
        $this->assertNull($fresh->last_tested_at);
        $this->assertSame(
            0,
            DataSourceHealthCheck::query()->withoutTenantIsolation()->where('data_source_id', $source->id)->count()
        );
    }

    public function test_tenant_context_restored_between_sequential_tenant_operations(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$userB, $tenantB] = $this->makeTenantUser('tenant_owner');
        $context = app(TenantContext::class);

        $this->actingAs($userA)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenantA->id,
            'provider' => 'wordpress',
            'display_name' => 'Tenant A Source',
        ])->assertCreated();

        $this->assertNull($context->id(), 'Tenant context must not leak after request A');

        $this->actingAs($userB)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenantB->id,
            'provider' => 'woocommerce',
            'display_name' => 'Tenant B Source',
        ])->assertCreated();

        $this->assertNull($context->id(), 'Tenant context must not leak after request B');

        $context->set($tenantA);
        $restored = $context->runWithTenant($tenantB, function () use ($context, $tenantB) {
            $this->assertSame($tenantB->id, $context->id());

            return 'ok';
        });
        $this->assertSame('ok', $restored);
        $this->assertSame($tenantA->id, $context->id());
        $context->clear();
    }

    public function test_run_with_tenant_restores_previous_on_exception(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $context = app(TenantContext::class);
        $context->set($tenantA);

        try {
            $context->runWithTenant($tenantB, function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($tenantA->id, $context->id());
        $context->clear();
    }

    private function createSource(User $user, Tenant $tenant, string $provider = 'wordpress'): string
    {
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        return $this->actingAs($user)->postJsonWithCsrf('/api/v1/data-sources', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'provider' => $provider,
            'display_name' => $provider.' remediation source',
            'mode' => 'mock',
            'auth_method' => 'none',
            'settings' => [
                'fixture_dataset' => 'success',
                'pagination_size' => 10,
                'simulated_error_scenario' => 'none',
                'reporting_timezone' => 'UTC',
                'mock_external_resource_id' => 'mock-'.$provider.'-'.$site->id,
            ],
        ])->assertCreated()->json('data.id');
    }
}
