<?php

namespace Tests\Feature\Connectors;

use App\Connectors\Registry\ConnectorRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockConnectorTest extends TestCase
{
    public function test_successful_data_retrieval(): void
    {
        [$user] = $this->makeTenantUser('analyst');

        $this->actingAs($user)
            ->getJson('/api/v1/mock-connectors/wordpress?scenario=success&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.connector', 'wordpress')
            ->assertJsonCount(5, 'data');
    }

    public function test_pagination(): void
    {
        $registry = app(ConnectorRegistry::class);
        $page1 = $registry->get('woocommerce')->fetch(['scenario' => 'pagination', 'page' => 1, 'per_page' => 10]);
        $page2 = $registry->get('woocommerce')->fetch(['scenario' => 'pagination', 'page' => 2, 'per_page' => 10]);

        $this->assertCount(10, $page1['data']);
        $this->assertCount(10, $page2['data']);
        $this->assertSame(25, $page1['meta']['total']);
        $this->assertNotSame($page1['data'][0]['id'], $page2['data'][0]['id']);
    }

    public function test_error_simulation_scenarios(): void
    {
        [$user] = $this->makeTenantUser('analyst');

        $this->actingAs($user)
            ->getJson('/api/v1/mock-connectors/google_analytics_4?scenario=rate_limit')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limit');

        $this->actingAs($user)
            ->getJson('/api/v1/mock-connectors/tutor_lms_pro?scenario=unauthorized')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_no_external_network_access(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $registry = app(ConnectorRegistry::class);

        foreach ($registry->all() as $connector) {
            $connector->testConnection();
            $connector->health();
            $connector->fetch(['scenario' => 'success', 'per_page' => 2]);
            $connector->simulateError('temporary_failure');
        }

        Http::assertNothingSent();
        $this->assertFalse((bool) config('codina.allow_external_http'));
    }

    public function test_mock_connectors_index_lists_all_five(): void
    {
        [$user] = $this->makeTenantUser('viewer');

        $response = $this->actingAs($user)->getJson('/api/v1/mock-connectors')->assertOk();
        $keys = collect($response->json('data'))->pluck('key')->sort()->values()->all();

        $this->assertSame([
            'google_analytics_4',
            'google_search_console',
            'tutor_lms_pro',
            'woocommerce',
            'wordpress',
        ], $keys);
    }
}
