<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedBigInteger('sync_run_id');
            $table->string('entity_type', 64);
            $table->string('scope_key', 128);
            $table->unsignedInteger('page_number');
            $table->json('cursor_before_json')->nullable();
            $table->json('cursor_after_json')->nullable();
            $table->string('payload_hash', 64);
            $table->string('commit_key', 64);
            $table->string('status', 32);
            $table->unsignedInteger('rows_received')->default(0);
            $table->unsignedInteger('rows_accepted')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'sync_run_id'])
                ->references(['tenant_id', 'id'])
                ->on('sync_runs')
                ->cascadeOnDelete();

            // Deterministic unique key for committed page processing (scope + page + payload).
            $table->unique(['commit_key'], 'ingestion_batches_commit_key_unique');
            $table->index(['tenant_id', 'sync_run_id', 'page_number'], 'ingestion_batches_run_page_index');
            $table->index(
                ['data_source_id', 'entity_type', 'scope_key', 'page_number'],
                'ingestion_batches_scope_page_index'
            );
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batches');
    }
};
