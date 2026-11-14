<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sync_run_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_summary', 512)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sync_run_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_runs')
                ->cascadeOnDelete();

            $table->unique(['sync_run_id', 'attempt_number'], 'sync_attempts_run_number_unique');
            $table->index(['tenant_id', 'sync_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_attempts');
    }
};
