<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-100 / ADR-006: per-tenant switches gating every external
 * integration. Absence of a row means OFF - a tenant only reaches M-Pesa
 * or KRA once an operator has explicitly enabled it (after ACC-003 /
 * ACC-004), so a guessed provider contract can ship without going live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('enabled')->default(false);
            $table->string('changed_by');
            $table->text('reason');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_feature_flags');
    }
};
