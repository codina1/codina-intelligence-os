<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            // Non-null scope sentinel (0 = no tenant) so UNIQUE works under MariaDB NULL semantics.
            $table->unsignedBigInteger('tenant_scope_id')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('route');
            $table->string('request_hash', 64);
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['tenant_scope_id', 'user_id', 'key', 'route'], 'idempotency_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
