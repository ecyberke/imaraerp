<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.4: "Partial delivery is a many-GRNLine-to-one-
 * PurchaseOrderLine relationship" - purchase_order_line_id is a plain
 * FK, not unique, so two GRNLines can reference the same PO line across
 * two shipments. unit_cost_cents is base currency (already converted via
 * the PO's exchange_rate at receipt time) - the actual figure
 * stock_ledger/StockQuarantine record. stock_quarantine_id links to the
 * inventory-core quarantine row this line's receipt created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grn_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained();
            $table->foreignId('item_id')->constrained();
            $table->decimal('quantity_received', 15, 4);
            $table->bigInteger('unit_cost_cents');
            $table->string('quarantine_status')->default('pending');
            $table->foreignId('stock_quarantine_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('grn_id');
            $table->index('purchase_order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_lines');
    }
};
