<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * execution_plan.md asks for "BankAccount reconciliation UI beyond the
 * Phase 1 minimal entity" in this branch - but architecture §14's
 * backlog explicitly defers the full Banking module (statement import,
 * BankTransaction, BankReconciliation as real entities) to later. Where
 * the two disagree, the architecture doc wins (execution_plan.md's own
 * stated precedence rule) - resolved as a lightweight MANUAL
 * reconciliation against the Payment rows that already exist (no
 * statement import, no new BankTransaction/BankReconciliation entities):
 * a Payment can be marked reconciled against a bank statement a human
 * is looking at, and the reconciliation screen compares the GL balance
 * for that BankAccount against the sum of its unreconciled Payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('is_reconciled')->default(false);
            $table->timestamp('reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['is_reconciled', 'reconciled_at']);
        });
    }
};
