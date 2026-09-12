<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role is tenant-scoped (architecture doc §11 / v20), not a global shared
 * catalog — consistent with §3.1's "no exceptions" tenancy rule and the same
 * per-tenant-rows-from-one-fixed-list pattern ChartOfAccounts already uses.
 * Every tenant gets the same nine roles, seeded automatically on Tenant
 * creation (see App\Models\Tenant::seedDefaultRoles()) — not seeded here,
 * since no Tenant exists yet at migration time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('label');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
