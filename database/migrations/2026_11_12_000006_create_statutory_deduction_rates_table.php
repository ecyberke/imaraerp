<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.11: "kept as data, not hardcoded logic, since these change on
 * government policy, not on a release cycle." rate_bands/employer_rate_bands
 * are JSON per deduction_type - StatutoryDeductionRateSeeder (this branch)
 * seeds current rates as a required Phase 1 deliverable per the doc, with
 * each rate's real-world shape documented in the seeder itself rather
 * than here, since the shapes differ meaningfully per deduction_type
 * (PAYE is marginal bands + a flat personal relief; NSSF is tiered with a
 * per-tier cap; SHIF/Housing Levy are flat percentages; NITA is a flat
 * employer-only amount; HELB is implemented as a simplified percentage-
 * banded schedule, flagged in the seeder - the real repayment schedule is
 * loan-specific per employee, not a single government-wide table, the
 * same class of "flagged simplification" this document applies elsewhere
 * (diminishing_balance's own rate-derivation choice, fixed-assets-plant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_deduction_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('deduction_type'); // paye, nssf, shif, housing_levy, helb, nita
            $table->date('effective_from');
            $table->json('rate_bands');
            $table->json('employer_rate_bands')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_deduction_rates');
    }
};
