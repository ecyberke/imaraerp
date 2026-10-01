<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "No on_leave status - leave is a state derived from LeaveRequest,
 * not a parallel flag on Employee." cumulative_casual_days_worked is only
 * ever incremented for employment_type=casual (Employment Act 2007 s.37
 * conversion tracking); stays null for every other type rather than 0, so
 * "never tracked" and "tracked, currently zero" remain distinguishable.
 *
 * No resource_id column here, despite §3.11 naming one - §3.6's Resource
 * already carries the other direction (employee_id, forward-referenced
 * since Employee didn't exist yet, backfilled with its real FK in
 * 2026_11_12_000013). Two FKs pointing at each other would be a
 * redundant bidirectional pointer with nothing enforcing the two stay in
 * sync - resolved the way this document's own prior reviews resolved the
 * same class of redundancy (v7's Subcontract.retention_terms_id removal):
 * one real column (resources.employee_id), with Employee::resource()
 * reading it from the other side instead of duplicating it.
 *
 * casual_conversion_due stands in for the not-yet-built Notification
 * (type casual_conversion_due, §3.11/§3.10) - same pattern as
 * ProductionOrder.bom_variance_exceeded and Project.dlp_ready_to_close.
 * TimesheetService's approve() increments cumulative_casual_days_worked
 * per approved worked day for employment_type=casual and sets this flag
 * once the Employment Act 2007 s.37 cumulative threshold (90 days,
 * standing in for "one month continuous or an equivalent three months
 * with breaks" - the continuous-month variant isn't separately detected,
 * flagged as a simplification) is crossed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('employee_number');
            $table->string('name');
            $table->string('id_number');
            $table->string('kra_pin');
            $table->string('nssf_number');
            $table->string('shif_number');
            $table->string('helb_account_number')->nullable();
            $table->string('employment_type'); // full_time, part_time, casual, contract
            $table->date('date_of_hire');
            $table->date('date_of_exit')->nullable();
            $table->unsignedInteger('cumulative_casual_days_worked')->nullable();
            $table->boolean('casual_conversion_due')->default(false);
            $table->string('status')->default('active'); // active, probation, notice_period, terminated
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
