<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subcontracts.boq_id was left as a plain nullable column with no FK in
 * procurement's create_subcontracts_table migration, since boqs didn't
 * exist yet - same forward-reference-then-backfill pattern as
 * stock_quarantines.grn_id/supplier_performance_logs.po_id (both
 * resolved in procurement once GRN/PO existed). Resolved here now that
 * boqs exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcontracts', function (Blueprint $table) {
            $table->foreign('boq_id')->references('id')->on('boqs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subcontracts', function (Blueprint $table) {
            $table->dropForeign(['boq_id']);
        });
    }
};
