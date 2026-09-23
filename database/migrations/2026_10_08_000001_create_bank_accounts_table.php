<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.10: "Moved to Phase 1 data layer (§8) - it was previously listed
 * as Phase 2, but the Master Dashboard's Cash Position widget (§10) is
 * Phase 1 and can't compute anything without it." No prior branch's
 * execution_plan.md bullet list actually names BankAccount, even though
 * the doc calls it Phase 1 data layer and this branch's own exit
 * criterion ("Cash Flow Statement's operating/investing totals
 * reconcile against actual BankAccount movements") depends on it
 * existing - a genuine gap between the architecture doc and every
 * prior branch's scope, closed here rather than silently worked around
 * with an ad hoc query. Minimal per §3.10: no statement import or
 * auto-reconciliation (Phase 2, §14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_number');
            $table->foreignId('currency_id')->constrained();
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts');
            $table->string('status')->default('active'); // active, closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
