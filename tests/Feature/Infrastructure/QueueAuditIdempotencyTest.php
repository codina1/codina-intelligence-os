<?php

namespace Tests\Feature\Infrastructure;

use App\Audit\Services\AuditLogger;
use App\Models\IdempotencyKey;
use App\Models\Site;
use App\Shared\Support\IdempotencyGuard;
use App\Synchronization\Jobs\TenantAwareJob;
use App\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
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
        $result = (new TenantAwareJob($tenant->id, $user->id))->handle(app(TenantContext::class));
        $this->assertTrue($result['ok']);
        $this->assertNull(app(TenantContext::class)->id());
    }

    public function test_failed_job_handling_records_failure_path(): void
    {
        [$user] = $this->makeTenantUser('tenant_owner');
        $foreignTenantId = \App\Models\Tenant::factory()->create()->id;

        try {
            (new TenantAwareJob($foreignTenantId, $user->id))->handle(app(TenantContext::class));
            $this->fail('Expected cross-tenant job rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cross-tenant', $e->getMessage());
        }

        $this->assertNull(app(TenantContext::class)->id(), 'Context cleared after failed job');
    }

    public function test_audit_log_creation(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)->postJsonWithCsrf('/api/v1/sites', [
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

    #[Group('critical')]
    public function test_idempotent_request_handling(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $payload = [
            'tenant_id' => $tenant->id,
            'name' => 'Idem Site',
            'primary_url' => 'https://idem.example.test',
        ];

        $first = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Idempotency-Key', 'idem-1')
            ->postJson('/api/v1/sites', $payload)
            ->assertCreated();

        $second = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Idempotency-Key', 'idem-1')
            ->postJson('/api/v1/sites', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Site::query()->withoutTenantIsolation()->where('name', 'Idem Site')->count());
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'idem-1')->count());
    }

    #[Group('critical')]
    public function test_idempotency_conflict_on_payload_mismatch(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');

        $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Idempotency-Key', 'idem-conflict')
            ->postJson('/api/v1/sites', [
                'tenant_id' => $tenant->id,
                'name' => 'First',
                'primary_url' => 'https://first.example.test',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Idempotency-Key', 'idem-conflict')
            ->postJson('/api/v1/sites', [
                'tenant_id' => $tenant->id,
                'name' => 'Second',
                'primary_url' => 'https://second.example.test',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    #[Group('critical')]
    public function test_idempotency_rolls_back_and_allows_retry_without_deleting_claim(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $guard = app(IdempotencyGuard::class);

        $payload = json_encode([
            'tenant_id' => $tenant->id,
            'name' => 'Rollback Site',
            'primary_url' => 'https://rollback.example.test',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/v1/sites', 'POST', [], [], [], [], $payload);
        $request->headers->set('Idempotency-Key', 'idem-rollback');
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('tenant_id', $tenant->id);

        try {
            $guard->handle($request, $user, function () {
                Site::factory()->create(['name' => 'Should Roll Back']);
                throw new \RuntimeException('simulated failure after local write began');
            });
            $this->fail('Expected simulated failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure after local write began', $e->getMessage());
        }

        $claim = IdempotencyKey::query()->where('key', 'idem-rollback')->first();
        $this->assertNotNull($claim);
        $this->assertSame(IdempotencyGuard::STATUS_RETRYABLE, $claim->status);
        $this->assertSame(0, Site::query()->withoutTenantIsolation()->where('name', 'Should Roll Back')->count());

        $retry = $guard->handle($request, $user, function () use ($tenant) {
            $site = Site::factory()->create([
                'tenant_id' => $tenant->id,
                'name' => 'Retry Site',
            ]);

            return response()->json([
                'data' => ['id' => $site->id, 'name' => $site->name],
                'meta' => ['request_id' => 'retry', 'schema_version' => '1.0'],
            ], 201);
        });

        $this->assertSame(201, $retry->getStatusCode());
        $claim->refresh();
        $this->assertSame(IdempotencyGuard::STATUS_COMPLETED, $claim->status);
        $this->assertSame(1, Site::query()->withoutTenantIsolation()->where('name', 'Retry Site')->count());
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'idem-rollback')->count());
    }

    #[Group('critical')]
    public function test_terminal_failed_idempotency_rejects_retry(): void
    {
        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $guard = app(IdempotencyGuard::class);

        $payload = json_encode(['tenant_id' => $tenant->id], JSON_THROW_ON_ERROR);
        $request = Request::create('/api/v1/sites', 'POST', [], [], [], [], $payload);
        $request->headers->set('Idempotency-Key', 'idem-failed');
        $request->attributes->set('tenant_id', $tenant->id);

        $claim = IdempotencyKey::query()->create([
            'tenant_id' => $tenant->id,
            'tenant_scope_id' => $tenant->id,
            'user_id' => $user->id,
            'key' => 'idem-failed',
            'route' => 'POST api/v1/sites',
            'request_hash' => hash('sha256', $payload),
            'status' => IdempotencyGuard::STATUS_PENDING,
        ]);
        $guard->markFailed($claim, 'External side effect may have occurred.');

        $response = $guard->handle($request, $user, function () {
            $this->fail('Callback must not run for terminal failed keys.');
        });

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('idempotency_failed', $response->getData(true)['error']['code']);
    }

    public function test_nullable_tenant_scope_uniqueness_is_enforced(): void
    {
        $user = \App\Models\User::factory()->create();
        $now = now();

        $attrs = [
            'tenant_id' => null,
            'tenant_scope_id' => 0,
            'user_id' => $user->id,
            'key' => 'null-tenant-key',
            'route' => 'POST api/v1/tenants',
            'request_hash' => hash('sha256', 'a'),
            'status' => 'completed',
            'response_code' => 201,
            'response_body' => json_encode(['ok' => true]),
            'expires_at' => $now->copy()->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $guard = app(IdempotencyGuard::class);
        $this->assertTrue($guard->claimForTest($attrs));
        $this->assertFalse($guard->claimForTest($attrs));
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'null-tenant-key')->count());
    }

    public function test_tenant_context_does_not_leak_across_sequential_jobs(): void
    {
        [$userA, $tenantA] = $this->makeTenantUser('tenant_owner');
        [$userB, $tenantB] = $this->makeTenantUser('tenant_owner');
        $context = app(TenantContext::class);

        (new TenantAwareJob($tenantA->id, $userA->id, 'job-a'))->handle($context);
        $this->assertNull($context->id());

        $resultB = (new TenantAwareJob($tenantB->id, $userB->id, 'job-b'))->handle($context);
        $this->assertSame($tenantB->id, $resultB['tenant_id']);
        $this->assertNull($context->id());
    }

    public function test_real_database_queue_processing_and_failed_jobs(): void
    {
        config(['queue.default' => 'database']);

        [$user, $tenant] = $this->makeTenantUser('tenant_owner');
        $foreign = \App\Models\Tenant::factory()->create();

        TenantAwareJob::dispatch($tenant->id, $user->id, 'db-queue-ok');
        $this->assertDatabaseCount('jobs', 1);

        $exit = Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => 'default',
            '--tries' => 1,
        ]);
        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull(app(TenantContext::class)->id());

        DB::table('failed_jobs')->delete();
        TenantAwareJob::dispatch($foreign->id, $user->id, 'db-queue-fail');
        $this->assertDatabaseCount('jobs', 1);

        Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => 'default',
            '--tries' => 1,
        ]);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertNull(app(TenantContext::class)->id());
    }
}
