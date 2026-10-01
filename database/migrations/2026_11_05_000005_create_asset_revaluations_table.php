<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.12/v8's self-caught fix: net_book_value_at_revaluation and
 * accumulated_depreciation_at_revaluation are each their own stored,
 * immutable column - never recomputed on read, never sharing a column
 * with residual_value. This is the exact bug the Sali Assets code audit
 * caught (accumulated depreciation was stored in the residual-value field,
 * computing NBV-at-revaluation as always purchase_cost minus itself, i.e.
 * zero) - deliberately not repeated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained();
            $table->date('revaluation_date');
            $table->bigInteger('net_book_value_at_revaluation_cents');
            $table->bigInteger('accumulated_depreciation_at_revaluation_cents');
            $table->bigInteger('new_valuation_cents');
            $table->bigInteger('new_residual_value_cents')->nullable();
            $table->unsignedInteger('new_useful_life_years')->nullable();
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_revaluations');
    }
};
