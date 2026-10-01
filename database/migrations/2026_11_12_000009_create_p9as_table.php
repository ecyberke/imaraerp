<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** §3.11: "cached annual summary ... generated from the year's Payslips rather than recalculated ad hoc at filing time." */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p9as', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->bigInteger('taxable_pay_cents');
            $table->bigInteger('total_paye_cents');
            $table->bigInteger('personal_relief_applied_cents');
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p9as');
    }
};
