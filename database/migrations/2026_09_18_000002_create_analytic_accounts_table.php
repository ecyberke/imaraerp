<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.7: cost_code-bearing analytic tag every JournalLine can
 * optionally carry alongside its account_id, for project-level P&L. Not
 * linked to Project yet (Project doesn't exist until projects-milestones-ui,
 * Phase 2) - standalone lookup table for now, gains a project_id FK when
 * that branch lands. Minimal on purpose: cost_code + name is all §3.7
 * actually specifies at this point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytic_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('cost_code');
            $table->string('name');
            $table->timestamps();

            $table->unique(['tenant_id', 'cost_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytic_accounts');
    }
};
