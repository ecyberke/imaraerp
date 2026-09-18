<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.9: each JournalLine carries an optional analytic_account_id
 * alongside its normal account_id, plus an optional tax_code_id - no memo
 * field (deliberately, per the doc: per-cost-type/detail breakdowns live on
 * the source entity, reachable via JournalEntry.reference_id, not
 * duplicated here).
 *
 * Money representation (App\Support\Money): debit_cents/credit_cents as
 * bigInteger, never both non-zero on the same line, neither negative -
 * mirrors the reference JournalLine constructor's own validation exactly,
 * enforced in the JournalLine model.
 *
 * tenant_id duplicated here (not just reachable via journal_entry_id) per
 * §1.1's own explicit indexing guidance: "(tenant_id, posting_date) ...
 * on JournalLine" - stated as a JournalLine-level index, meaning
 * JournalLine carries tenant_id directly, not just through its parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->foreignId('analytic_account_id')->nullable()->constrained('analytic_accounts')->nullOnDelete();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->nullOnDelete();
            $table->bigInteger('debit_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'account_id']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
