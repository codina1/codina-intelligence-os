<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_references', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->string('credential_type', 64);
            $table->string('secret_reference', 255);
            $table->string('status', 32)->default('mock');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(['data_source_id', 'credential_type'], 'cred_ref_source_type_unique');
            $table->index(['tenant_id', 'data_source_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_references');
    }
};
