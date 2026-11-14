<?php

namespace Tests\Feature\Infrastructure;

use App\Audit\Services\AuditLogger;
use App\Models\IdempotencyKey;
use App\Models\Site;
use App\Shared\Support\IdempotencyGuard;
use App\Synchronization\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueAuditIdempotencyTest extends TestCase
{
    public function test_queue_dispatch_and_processing(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        Queue::fake();
        TenantAwareJob::dispatch($tenant->id, $user->id, 'sync.ping');
        Queue::assertPushed(TenantAwareJob::class, function (TenantAwareJob $job) use ($tenant, $user) {
            return $job->tenantId === $tenant->id && $job->userId === $user->id;
        });

        Queue::swap(app('queue'));
        $result = (new TenantAwareJob($tenant->id, $user->id))->handle(app(\App\Tenancy\Services\TenantContext::class));
        $this->assertTrue($result['ok']);
    }

    public function test_failed_job_handling_records_failure_path(): void
    {
        [$user] = $this->makeTenantUser('tenant_owner');
        $foreignTenantId = \App\Models\Tenant::factory()->create()->id;

        try {
            (new TenantAwareJob($foreignTenantId, $user->id))->handle(app(\App\Tenancy\Services\TenantContext::class));
            $this->fail('Expected cross-tenant job rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cross-tenant', $e->getMessage());
        }
    }

    public function test_audit_log_creation(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJson('/api/v1/sites', [
            'tenant_id' => $tenant->id,
            'name' => 'Audited Site',
            'primary_url' => 'https://audited.example.test',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'site.created',
        ]);

        $logger = app(AuditLogger::class);
        $logger->log('manual.event', $user, null, ['password' => 'secret'], $tenant->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'manual.event',
            'tenant_id' => $tenant->id,
        ]);

        $payload = \App\Models\AuditLog::query()->where('action', 'manual.event')->value('payload');
        $this->assertSame('[redacted]', $payload['password']);
    }

    public function test_idempotent_request_handling(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $guard = app(IdempotencyGuard::class);
        $request = request()->create('/api/v1/sites', 'POST', [
            'tenant_id' => $tenant->id,
            'name' => 'Idem Site',
            'primary_url' => 'https://idem.example.test',
        ]);
        $request->headers->set('Idempotency-Key', 'idem-1');
        $request->attributes->set('tenant_id', $tenant->id);
        app()->instance('request', $request);

        $first = $guard->handle($request, $user, function () use ($tenant) {
            $site = Site::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Idem Site',
            ]);

            return response()->json([
                'data' => ['id' => $site->id, 'name' => $site->name],
                'meta' => ['request_id' => 'x', 'schema_version' => '1.0'],
            ], 201);
        });

        $second = $guard->handle($request, $user, function () {
            $this->fail('Callback should not run for replayed idempotent request.');
        });

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(201, $second->getStatusCode());
        $this->assertSame($first->getData(true), $second->getData(true));
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'idem-1')->count());
    }
}
