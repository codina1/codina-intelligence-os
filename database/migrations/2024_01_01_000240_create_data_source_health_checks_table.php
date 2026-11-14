<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_health_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('check_type', 64);
            $table->string('status', 32);
            $table->unsignedInteger('duration_ms');
            $table->string('error_code', 64)->nullable();
            $table->json('result_metadata')->nullable();
            $table->timestamp('checked_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'data_source_id', 'checked_at'], 'dshc_tenant_source_checked_idx');
            $table->index(['data_source_id', 'status'], 'dshc_source_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_health_checks');
    }
};
