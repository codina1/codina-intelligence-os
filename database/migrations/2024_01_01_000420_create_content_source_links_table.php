<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_source_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('content_entity_id');
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedBigInteger('ingestion_record_id')->nullable();
            $table->string('relation_type', 64)->default('primary');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'content_entity_id'])
                ->references(['tenant_id', 'id'])
                ->on('content_entities')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(
                ['content_entity_id', 'data_source_id', 'relation_type'],
                'content_source_links_entity_source_relation_unique'
            );
            $table->unique(
                ['data_source_id', 'ingestion_record_id'],
                'content_source_links_source_ingestion_unique'
            );
            $table->index(
                ['tenant_id', 'content_entity_id'],
                'content_source_links_tenant_entity_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_source_links');
    }
};
