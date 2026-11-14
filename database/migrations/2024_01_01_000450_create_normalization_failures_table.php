<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('normalization_run_id');
            $table->unsignedBigInteger('ingestion_record_id')->nullable();
            $table->string('error_code', 64);
            $table->json('safe_diagnostics_json')->nullable();
            $table->timestamp('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'normalization_run_id'])
                ->references(['tenant_id', 'id'])
                ->on('normalization_runs')
                ->cascadeOnDelete();

            $table->index(
                ['tenant_id', 'normalization_run_id'],
                'normalization_failures_tenant_run_index'
            );
            $table->index(
                ['ingestion_record_id'],
                'normalization_failures_ingestion_record_index'
            );
            $table->index(['error_code'], 'normalization_failures_error_code_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_failures');
    }
};
