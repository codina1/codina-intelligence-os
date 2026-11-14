<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedBigInteger('source_ingestion_record_id')->nullable();
            $table->string('provider', 64);
            $table->string('metric_key', 128);
            $table->decimal('metric_value', 20, 6)->nullable();
            $table->string('unit', 32)->nullable();
            $table->json('dimensions_json')->nullable();
            $table->string('observation_identity_hash', 64);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('source_payload_hash', 64);
            $table->string('mapper_version', 32);
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

            $table->unique(
                ['data_source_id', 'observation_identity_hash'],
                'metric_observations_source_identity_unique'
            );
            $table->index(
                ['tenant_id', 'provider', 'metric_key'],
                'metric_observations_tenant_provider_metric_index'
            );
            $table->index(
                ['tenant_id', 'data_source_id'],
                'metric_observations_tenant_source_index'
            );
            $table->index(
                ['source_ingestion_record_id'],
                'metric_observations_ingestion_record_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_observations');
    }
};
