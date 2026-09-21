<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4: allocated across the GRN's GRNLine rows
 * proportionally to value, converted to base currency at posting -
 * amount_cents is stored in the given currency_id, converted via
 * exchange_rate when LedgerPostingService::allocateLandedCostProportionally
 * runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grn_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->string('cost_type'); // freight, duty, clearing, insurance
            $table->bigInteger('amount_cents');
            $table->foreignId('currency_id')->constrained();
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_costs');
    }
};
