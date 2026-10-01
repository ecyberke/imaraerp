<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "EmploymentContract.date_of_exit triggers a FinalSettlement ...
 * Prorates the terminating month's salary." payslip_id links to the
 * prorated Payslip row this settlement's figures actually produced (the
 * thing that posts through PayrollRun) - the doc describes FinalSettlement
 * as computing the proration, not as a second, separate ledger posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->date('last_working_day');
            $table->decimal('days_worked_this_period', 5, 2);
            $table->decimal('accrued_leave_days', 5, 2);
            $table->bigInteger('leave_encashment_amount_cents')->nullable();
            $table->bigInteger('final_paye_amount_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_settlements');
    }
};
