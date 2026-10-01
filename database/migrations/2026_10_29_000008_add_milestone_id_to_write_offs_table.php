<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5.4: "unbilled Milestones written off via the same Write-off mechanism
 * as an uncollectable invoice" on Project cancellation. Literally reusing
 * WriteOffService::writeOffInvoice() would be wrong here - an unbilled
 * Milestone was never invoiced, so there is no AR balance to credit and no
 * Bad-Debt-Expense/AR journal entry to post. What *is* reused is the
 * WriteOff record itself as the audit trail shape (reason, approved_by) -
 * journal_entry_id stays null for a milestone write-off since nothing was
 * ever recognized in the ledger to reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('write_offs', function (Blueprint $table) {
            $table->foreignId('milestone_id')->nullable()->after('progress_claim_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('write_offs', function (Blueprint $table) {
            $table->dropForeign(['milestone_id']);
            $table->dropColumn('milestone_id');
        });
    }
};
