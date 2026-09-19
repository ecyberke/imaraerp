<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.3/§6: a GRN receipt first inserts here, never directly
 * into stock_ledger - only a passed QC disposition triggers the
 * stock_ledger insert. qc_status is a denormalized summary kept in sync
 * by QualityCheck (§3.3/§3.4, "authority stated explicitly" - QualityCheck
 * is the source of truth if the two ever disagree).
 *
 * grn_id: nullable, no FK - GoodsReceiptNote doesn't exist until
 * procurement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_quarantines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('grn_id')->nullable();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 15, 4);
            $table->bigInteger('unit_cost_cents');
            $table->string('qc_status')->default('pending'); // pending, passed, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_quarantines');
    }
};
