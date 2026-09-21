<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * unit_cost_cents is in the PurchaseOrder's own currency (minor units,
 * not necessarily KES) - converted to base currency via
 * PurchaseOrder.exchange_rate only when it lands in stock_ledger/GRNLine
 * (§3.4: "stock_ledger.unit_cost is always recorded in base currency
 * (quantity x rate x exchange_rate)").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->decimal('quantity_ordered', 15, 4);
            $table->bigInteger('unit_cost_cents');
            $table->timestamps();

            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
