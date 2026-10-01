<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "Non-overlap enforced - the system refuses a second contract on
 * the same employee with overlapping active date ranges." Enforced in
 * EmploymentContractService, not a DB constraint (Postgres exclusion
 * constraints over date ranges exist but would be the only one in this
 * schema and add real migration complexity for one rule already enforced
 * at the service layer everywhere else in this codebase, e.g. VO/BOQ-line
 * row locks). basic_salary is mutually exclusive with hourly_rate/
 * daily_rate per the doc - enforced the same way, not via a DB CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->string('contract_type'); // full_time, part_time, casual, contract
            $table->string('pay_frequency'); // monthly, weekly, daily
            $table->bigInteger('basic_salary_cents')->nullable();
            $table->bigInteger('hourly_rate_cents')->nullable();
            $table->bigInteger('daily_rate_cents')->nullable();
            $table->decimal('overtime_multiplier_weekday', 4, 2)->default(1.5);
            $table->decimal('overtime_multiplier_restday', 4, 2)->default(2.0);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_contracts');
    }
};
