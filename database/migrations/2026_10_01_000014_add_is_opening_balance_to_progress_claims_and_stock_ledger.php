<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8: "each gaining an is_opening_balance boolean so reports and
 * reconciliation can distinguish migrated history from live
 * transactions" - progress_claims and stock_ledger are the two
 * pre-existing (procurement/inventory-core) tables OpeningBalanceBatch
 * needs to backfill into; invoices/payments/payment_allocations/
 * retention_accounts already got the column directly since they're new
 * in this same branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('progress_claims', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('journal_entry_id');
        });

        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->boolean('is_opening_balance')->default(false)->after('reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('progress_claims', function (Blueprint $table) {
            $table->dropColumn('is_opening_balance');
        });

        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn('is_opening_balance');
        });
    }
};
