<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiEnvelopeTest extends TestCase
{
    public function test_health_endpoint_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['service', 'phase', 'status', 'checks'],
                'meta' => ['request_id', 'schema_version'],
            ])
            ->assertJsonPath('meta.schema_version', '1.0')
            ->assertJsonPath('data.checks.external_http_allowed', false);
    }

    public function test_validation_errors_use_structured_envelope(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)
            ->postJsonWithCsrf('/api/v1/sites', [
                'tenant_id' => $tenant->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details'],
                'meta' => ['request_id', 'schema_version'],
            ]);
    }

    public function test_authentication_and_authorization_errors(): void
    {
        $this->getJson('/api/v1/sites')->assertStatus(401);

        [$viewer, $tenant] = $this->makeTenantUser('viewer');

        $this->actingAs($viewer)
            ->postJsonWithCsrf('/api/v1/sites', [
                'tenant_id' => $tenant->id,
                'name' => 'Nope',
                'primary_url' => 'https://nope.example.test',
            ])
            ->assertStatus(403);
    }

    public function test_sites_pagination_meta(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        \App\Models\Site::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/sites?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_request_id_header_is_echoed(): void
    {
        $this->withHeader('X-Request-Id', 'req-test-123')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-test-123')
            ->assertJsonPath('meta.request_id', 'req-test-123');
    }
}
