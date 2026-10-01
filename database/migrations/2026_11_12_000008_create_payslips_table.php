<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: period_start/period_end aren't in the doc's bare field list -
 * needed because a mid-period casual-to-permanent conversion produces
 * TWO Payslip rows under the SAME PayrollRun, each covering a different
 * sub-range of that run's own period_start/period_end (one under the old
 * EmploymentContract to the conversion date, one under the new from
 * there to period end) - without its own period on the row, there would
 * be no way to tell which days each Payslip actually covers.
 * employment_contract_id is the same kind of necessary addition - the
 * two conversion-split rows must each point at a different contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('employment_contract_id')->constrained();
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('gross_pay_cents');
            $table->bigInteger('taxable_pay_cents');
            $table->decimal('days_paid_not_worked', 5, 2)->default(0);
            $table->bigInteger('paye_amount_cents')->default(0);
            $table->bigInteger('personal_relief_applied_cents')->default(0);
            $table->bigInteger('nssf_amount_cents')->default(0);
            $table->bigInteger('shif_amount_cents')->default(0);
            $table->bigInteger('housing_levy_amount_cents')->default(0);
            $table->bigInteger('helb_amount_cents')->default(0);
            $table->bigInteger('other_deductions_cents')->default(0);
            $table->bigInteger('employer_nssf_amount_cents')->default(0);
            $table->bigInteger('employer_housing_levy_amount_cents')->default(0);
            $table->bigInteger('net_pay_cents');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
