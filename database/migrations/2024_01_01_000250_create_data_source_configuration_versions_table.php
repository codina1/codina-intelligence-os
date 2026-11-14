<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_source_configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedInteger('version_number');
            $table->json('settings_snapshot');
            $table->unsignedBigInteger('changed_by');
            $table->string('change_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id', 'dscv_tenant_fk')
                ->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'], 'dscv_tenant_source_fk')
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();
            $table->foreign('changed_by', 'dscv_changed_by_fk')
                ->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['data_source_id', 'version_number'], 'dscv_source_version_unique');
            $table->index(['tenant_id', 'data_source_id'], 'dscv_tenant_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_source_configuration_versions');
    }
};
