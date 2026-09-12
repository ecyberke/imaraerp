<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.10 / §1.1: storage for idempotency tokens on
 * financial-mutation frontend requests and inbound webhooks. A repeated
 * request with the same key returns the stored response_payload rather
 * than re-executing the mutation; a null response_payload means the
 * original request is still in flight (duplicate-in-progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('endpoint');
            $table->json('response_payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
