<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('status', 32)->default('queued');
            $table->string('mapper_version', 32);
            $table->unsignedInteger('records_scanned')->default(0);
            $table->unsignedInteger('entities_created')->default(0);
            $table->unsignedInteger('entities_updated')->default(0);
            $table->unsignedInteger('entities_unchanged')->default(0);
            $table->unsignedInteger('versions_created')->default(0);
            $table->unsignedInteger('metrics_created')->default(0);
            $table->unsignedInteger('metrics_updated')->default(0);
            $table->unsignedInteger('records_rejected')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->boolean('is_simulated')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'id'], 'normalization_runs_tenant_id_id_unique');
            $table->index(
                ['tenant_id', 'data_source_id', 'status'],
                'normalization_runs_tenant_source_status_index'
            );
            $table->index(['tenant_id', 'status'], 'normalization_runs_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_runs');
    }
};
