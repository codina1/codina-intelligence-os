<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('provider', 64);
            $table->string('display_name');
            $table->string('external_resource_id')->nullable();
            $table->string('mode', 32)->default('mock');
            $table->string('status', 32)->default('draft');
            $table->string('auth_method', 64)->nullable();
            $table->json('settings');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'site_id'])->references(['tenant_id', 'id'])->on('sites')->cascadeOnDelete();

            // Enables child composite FKs: (tenant_id, data_source_id) -> (tenant_id, id)
            $table->unique(['tenant_id', 'id'], 'data_sources_tenant_id_id_unique');
            $table->index(['tenant_id', 'provider']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'mode']);
            $table->index(['tenant_id', 'site_id']);
        });

        // Explicit null-safe uniqueness: one provider registration per tenant site scope.
        // site_scope_id = IFNULL(site_id, 0) so nullable site_id does not weaken uniqueness.
        DB::statement('ALTER TABLE data_sources ADD COLUMN site_scope_id BIGINT UNSIGNED
            GENERATED ALWAYS AS (IFNULL(site_id, 0)) STORED');
        DB::statement('CREATE UNIQUE INDEX data_sources_tenant_provider_site_scope_unique
            ON data_sources (tenant_id, provider, site_scope_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
