<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT a §3-named entity, but implied - ledger-core's
 * postPostAcceptanceSupplierReturn() already references 'SupplierReturn'
 * as its reference_type string. Distinct from the QC-failure return path
 * (StockQuarantine result=fail never enters stock_ledger at all) - this
 * is stock that already passed QC and was accepted, returned later
 * (over-ordered, wrong spec, storage damage), so it DOES need a
 * stock_ledger 'return' movement reversing what was previously received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 15, 4);
            $table->bigInteger('unit_cost_cents');
            $table->boolean('already_paid')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
    }
};
