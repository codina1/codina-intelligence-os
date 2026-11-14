<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity_type', 64);
            $table->string('scope_key', 128);
            $table->json('cursor_json')->nullable();
            $table->unsignedInteger('last_completed_page')->default(0);
            $table->unsignedBigInteger('last_completed_run_id')->nullable();
            $table->json('committed_watermark_json')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            // Simple FK: MariaDB rejects ON DELETE SET NULL on composite keys that
            // include non-nullable tenant_id alongside a nullable run id.
            $table->foreign('last_completed_run_id')
                ->references('id')
                ->on('sync_runs')
                ->nullOnDelete();

            $table->unique(
                ['data_source_id', 'entity_type', 'scope_key'],
                'sync_checkpoints_source_entity_scope_unique'
            );
            $table->index(['tenant_id', 'data_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_checkpoints');
    }
};
