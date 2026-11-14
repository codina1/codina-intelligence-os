<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive upgrade path for databases created before tenant_scope_id existed.
 * Fresh installs already get the hardened schema from 000150.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('idempotency_keys', 'tenant_scope_id')) {
            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_scope_id')->default(0)->after('tenant_id');
                $table->string('status', 32)->default('completed')->after('request_hash');
                // Preserve FK eligibility before dropping the composite unique that covered tenant_id.
                $table->index('tenant_id', 'idempotency_keys_tenant_id_index');
            });

            DB::table('idempotency_keys')->update([
                'tenant_scope_id' => DB::raw('COALESCE(tenant_id, 0)'),
                'status' => DB::raw("IF(response_code IS NULL, 'pending', 'completed')"),
            ]);

            Schema::table('idempotency_keys', function (Blueprint $table) {
                $table->dropUnique('idempotency_unique_scope');
                $table->unique(['tenant_scope_id', 'user_id', 'key', 'route'], 'idempotency_unique_scope');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        // Irreversible safely for Phase 01 local DBs; leave columns in place.
    }
};
