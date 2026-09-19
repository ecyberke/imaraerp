<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.3: append-only *physical movement* log only -
 * reservations never appear here (they're commercial commitment,
 * SalesOrderReservation §3.2, not physical fact). unit_cost is always
 * base-currency Money (cents): the caller's input on an IN movement
 * (receipt, transfer_in, production_output, return), the valuation
 * service's computed relief cost on an OUT movement (issue, transfer_out,
 * production_consumption, scrap) - never re-derived from a source PO's
 * currency here.
 *
 * quantity is signed only for movement_type = 'adjustment' (a stock
 * correction can go either direction); every other movement_type's
 * quantity is always positive, with direction implied by the type itself
 * (StockValuationService::DIRECTIONS is the single source of truth for
 * that mapping - enforced in the model, not just documented).
 *
 * location_id is a plain nullable column, not a real FK - full WMS
 * location hierarchy stays Phase 2/backlog (§3.3's own words); cheap to
 * carry now, nothing to constrain against yet.
 *
 * reference_type/reference_id: nullable/no FK, same forward-reference
 * pattern as journal_entries - GoodsReceiptNote (procurement),
 * ProductionOrder (manufacturing) etc. don't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->string('movement_type');
            $table->decimal('quantity', 15, 4);
            $table->bigInteger('unit_cost_cents');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'item_id', 'warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
