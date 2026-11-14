<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('data_source_id');
            $table->string('entity_type', 64);
            $table->string('external_id', 191);
            $table->string('external_id_hash', 64);
            $table->string('content_type', 64);
            $table->string('title', 512)->nullable();
            $table->string('slug', 255)->nullable();
            $table->string('canonical_url', 1024)->nullable();
            $table->string('publication_status', 64)->nullable();
            $table->string('normalized_status', 32)->default('active');
            $table->unsignedInteger('current_version_number')->default(0);
            $table->string('normalized_payload_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('normalized_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            // Match data_sources: composite FK cannot use SET NULL while tenant_id is NOT NULL.
            $table->foreign(['tenant_id', 'site_id'])
                ->references(['tenant_id', 'id'])
                ->on('sites')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'id'], 'content_entities_tenant_id_id_unique');
            $table->unique(
                ['data_source_id', 'entity_type', 'external_id_hash'],
                'content_entities_source_entity_ext_unique'
            );
            $table->index(['tenant_id', 'content_type'], 'content_entities_tenant_content_type_index');
            $table->index(['tenant_id', 'data_source_id'], 'content_entities_tenant_source_index');
            $table->index(['tenant_id', 'normalized_status'], 'content_entities_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entities');
    }
};
