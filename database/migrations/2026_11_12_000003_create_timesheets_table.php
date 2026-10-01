<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "Where project_id is set, hours feed both payroll calculation
 * and the project's analytic_account_id - labour cost lands on the right
 * project automatically." hours_overtime_weekday/restday are separate
 * from hours_normal (different statutory rates, not a flat extension).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->date('date');
            $table->decimal('hours_normal', 5, 2)->default(0);
            $table->decimal('hours_overtime_weekday', 5, 2)->nullable();
            $table->decimal('hours_overtime_restday', 5, 2)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('submitted'); // submitted, approved, rejected
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
