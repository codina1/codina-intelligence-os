<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity_type', 64);
            $table->string('external_id', 191);
            $table->string('external_id_hash', 64);
            $table->string('payload_hash', 64);
            $table->json('payload_json');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('last_changed_at')->nullable();
            $table->unsignedBigInteger('last_sync_run_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(
                ['data_source_id', 'entity_type', 'external_id_hash'],
                'ingestion_records_ext_hash_unique'
            );
            $table->index(['tenant_id', 'data_source_id', 'entity_type'], 'ingestion_records_tenant_source_entity_index');
            $table->index(['last_sync_run_id'], 'ingestion_records_last_run_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_records');
    }
};
