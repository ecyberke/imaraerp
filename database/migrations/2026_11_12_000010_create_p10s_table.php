<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "cached employer-level monthly PAYE return summary across all
 * Payslips in that period's PayrollRuns." employee_count/total_gross_pay
 * aren't named explicitly but are what a PAYE return actually reports
 * alongside the total PAYE figure - added for the same reason P9A's own
 * fields were spelled out rather than left as "a summary."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p10s', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('employee_count');
            $table->bigInteger('total_gross_pay_cents');
            $table->bigInteger('total_paye_cents');
            $table->timestamps();

            $table->unique(['tenant_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p10s');
    }
};
