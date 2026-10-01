<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: per-tenant credentials for third-party integrations (M-Pesa
 * Daraja now, KRA eTIMS next) - entered on a Settings screen at client
 * onboarding, never hardcoded or put in .env, since every tenant has its
 * own Safaricom/KRA account. One row per (tenant, provider); `credentials`
 * is a provider-shaped JSON blob (see IntegrationCredential::FIELD_SCHEMA)
 * encrypted at rest via Laravel's `encrypted:array` cast - the table
 * itself never holds a plaintext secret, matching the same bar as a
 * password column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('credentials');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
