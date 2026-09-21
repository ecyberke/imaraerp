<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sales_orders.boq_id and sales_order_lines.boq_line_id were created
 * earlier in this same migration set as plain nullable columns (boqs/
 * boq_lines didn't exist yet at that point) - real FK constraints added
 * now that both tables exist, same forward-reference-then-backfill
 * pattern used across every prior branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreign('boq_id')->references('id')->on('boqs')->nullOnDelete();
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->foreign('boq_line_id')->references('id')->on('boq_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['boq_id']);
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropForeign(['boq_line_id']);
        });
    }
};
