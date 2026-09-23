<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * payments.bank_account_id was left as a plain nullable column with no
 * FK in finance-billing's create_payments_table migration, since
 * BankAccount didn't exist yet - same forward-reference-then-backfill
 * pattern used throughout (stock_quarantines.grn_id, subcontracts.boq_id,
 * etc.). Resolved here now that bank_accounts exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
        });
    }
};
