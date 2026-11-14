<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalization_active_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('data_source_id');
            $table->unsignedBigInteger('normalization_run_id')->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'data_source_id'])
                ->references(['tenant_id', 'id'])
                ->on('data_sources')
                ->cascadeOnDelete();

            $table->unique(['data_source_id'], 'normalization_active_scopes_source_unique');
            $table->index(['tenant_id', 'data_source_id']);
            $table->index(['lease_expires_at']);
        });

        Schema::table('normalization_runs', function (Blueprint $table) {
            $table->string('lease_owner', 64)->nullable()->after('is_simulated');
            $table->unsignedBigInteger('generation')->default(0)->after('lease_owner');
            $table->timestamp('lease_expires_at')->nullable()->after('generation');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('normalization_runs', function (Blueprint $table) {
            $table->dropColumn(['lease_owner', 'generation', 'lease_expires_at', 'heartbeat_at']);
        });

        Schema::dropIfExists('normalization_active_scopes');
    }
};
