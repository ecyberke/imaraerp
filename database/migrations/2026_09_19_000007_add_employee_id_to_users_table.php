<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture §3.1: "User — ... extended: employee_id (nullable — an
 * admin login with no payroll record has no Employee; a casual labourer
 * who never logs in has no User) ... User ↔ Employee is optional 1:1 in
 * both directions." No FK constraint yet - Employee doesn't exist until
 * hr-payroll (Phase 2); the constraint gets added there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
