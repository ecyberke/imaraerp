<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.7: "VariationOrderReversal - a compensating record referencing the
 * original VariationOrder, with its own line rows carrying the negative
 * of the original deltas." Modeled as a real, persisted header+lines
 * pair (not computed on the fly from the original VO's lines) for the
 * same reason the ledger is reversal-only rather than edit-in-place -
 * an immutable audit trail of exactly what was reversed and when,
 * independent of whatever the original VO's rows look like later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_order_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_order_id')->constrained();
            $table->text('reason');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('variation_order_reversal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_order_reversal_id')->constrained('variation_order_reversals')->cascadeOnDelete();
            $table->foreignId('boq_line_id')->nullable()->constrained('boq_lines')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('boq_sections')->nullOnDelete();
            $table->string('variation_type');
            $table->decimal('quantity_delta', 18, 4)->default(0);
            $table->bigInteger('rate_delta_cents')->default(0);
            $table->bigInteger('amount_delta_cents');
            $table->string('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_order_reversal_lines');
        Schema::dropIfExists('variation_order_reversals');
    }
};
