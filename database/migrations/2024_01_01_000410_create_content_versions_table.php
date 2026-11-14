<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('content_entity_id');
            $table->unsignedInteger('version_number');
            $table->string('normalized_payload_hash', 64);
            $table->json('normalized_payload_json');
            $table->unsignedBigInteger('source_ingestion_record_id')->nullable();
            $table->string('mapper_version', 32);
            $table->timestamp('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'content_entity_id'])
                ->references(['tenant_id', 'id'])
                ->on('content_entities')
                ->cascadeOnDelete();

            $table->unique(
                ['content_entity_id', 'version_number'],
                'content_versions_entity_version_unique'
            );
            $table->index(
                ['tenant_id', 'content_entity_id'],
                'content_versions_tenant_entity_index'
            );
            $table->index(
                ['source_ingestion_record_id'],
                'content_versions_ingestion_record_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
