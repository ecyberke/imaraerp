<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GoodsReceiptNote and PurchaseOrder now exist (procurement) - promoting
 * the plain, unconstrained forward-reference columns inventory-core left
 * for them (stock_quarantines.grn_id, supplier_performance_logs.po_id)
 * to real foreign keys, per the pattern documented in both of those
 * migrations at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_quarantines', function (Blueprint $table) {
            $table->foreign('grn_id')->references('id')->on('goods_receipt_notes')->nullOnDelete();
        });

        Schema::table('supplier_performance_logs', function (Blueprint $table) {
            $table->foreign('po_id')->references('id')->on('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_quarantines', function (Blueprint $table) {
            $table->dropForeign(['grn_id']);
        });

        Schema::table('supplier_performance_logs', function (Blueprint $table) {
            $table->dropForeign(['po_id']);
        });
    }
};
