<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('capability_key', 128);
            $table->string('availability_status', 32)->default('available');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(['data_source_id', 'capability_key'], 'dsc_source_capability_unique');
            $table->index(['tenant_id', 'data_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_capabilities');
    }
};
