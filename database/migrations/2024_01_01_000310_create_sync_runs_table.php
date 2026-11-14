<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity_type', 64);
            $table->string('scope_key', 128);
            $table->string('sync_type', 32);
            $table->string('status', 32)->default('queued');
            $table->unsignedBigInteger('generation')->default(0);
            $table->string('lease_owner', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rows_received')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_unchanged')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->unsignedInteger('rows_duplicate_in_batch')->default(0);
            $table->unsignedInteger('pages_completed')->default(0);
            $table->string('completeness_status', 32)->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_summary', 512)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('options_json')->nullable();
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'id'], 'sync_runs_tenant_id_id_unique');
            $table->index(['tenant_id', 'data_source_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['data_source_id', 'entity_type', 'scope_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
