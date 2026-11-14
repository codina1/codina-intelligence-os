<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Required for composite FK: data_sources(tenant_id, site_id) -> sites(tenant_id, id)
            $table->unique(['tenant_id', 'id'], 'sites_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique('sites_tenant_id_id_unique');
        });
    }
};
