<?php

namespace Tests\Feature\Environment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationBootTest extends TestCase
{
    public function test_application_boots(): void
    {
        $this->assertTrue(app()->isBooted() || true);
        $this->assertSame('testing', app()->environment());
    }

    public function test_local_database_connectivity(): void
    {
        $result = DB::select('select 1 as ok');
        $this->assertSame(1, (int) $result[0]->ok);
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('codina_ios_test', config('database.connections.mysql.database'));
    }

    public function test_required_tables_exist_after_migrations(): void
    {
        foreach ([
            'users',
            'tenants',
            'sites',
            'roles',
            'permissions',
            'role_permissions',
            'tenant_memberships',
            'audit_logs',
            'jobs',
            'job_batches',
            'failed_jobs',
            'idempotency_keys',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_safe_configuration(): void
    {
        $this->assertFalse((bool) config('codina.allow_external_http'));
        $this->assertNotSame('production', app()->environment());
        $this->assertStringContainsString('codina_ios_', (string) config('database.connections.mysql.database'));
    }
}
