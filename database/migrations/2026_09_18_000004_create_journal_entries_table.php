<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.9: the Shadow Ledger engine. Correction policy is
 * reversal-only, never edit-in-place - enforced at the service layer
 * (LedgerPostingService::reverse()), not by a DB trigger; this migration
 * models the two fields the doc explicitly names for it:
 * original_intended_posting_date (§3.10's AccountingPeriod auto-forward
 * case) and a self-referencing reversal link (not named as a field in the
 * doc, but needed for a reversal entry to actually point back at what it
 * reverses - a reasonable, minimal addition, not a doc contradiction).
 *
 * `reference_type`/`reference_id` are nullable: most of §7's source
 * entities (Invoice, PaymentAllocation, ProgressClaim, ...) don't exist
 * yet - they're built in later branches that will populate real FKs here.
 * `external_reference` carries the human-facing identifier in the
 * meantime (a test's ad-hoc ID today, a real DocumentSequence-formatted
 * number once document-issuing branches exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->date('posting_date');
            $table->date('original_intended_posting_date')->nullable();
            $table->foreignId('accounting_period_id')->constrained();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'posting_date']);
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
