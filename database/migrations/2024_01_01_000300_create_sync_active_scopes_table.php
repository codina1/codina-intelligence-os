<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_active_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity_type', 64);
            $table->string('scope_key', 128);
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->unsignedBigInteger('cancel_requested_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(
                ['data_source_id', 'entity_type', 'scope_key'],
                'sync_active_scopes_source_entity_scope_unique'
            );
            $table->index(['tenant_id', 'data_source_id']);
            $table->index(['lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_active_scopes');
    }
};
