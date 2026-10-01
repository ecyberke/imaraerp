<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * §3.11: "Seed data, stated as a required Phase 1 deliverable: current
 * PAYE bands, NSSF tiers, SHIF rate, Housing Levy rate, HELB schedule,
 * and the NITA amount must be seeded before the first payroll run."
 * Rates as understood at the time this branch was built (2026) - flagged
 * per deduction_type below since the shapes genuinely differ, and these
 * figures should be re-verified against the current regulatory position
 * before a real payroll run depends on them, the same caution v9 named
 * for SHIF specifically ("flagged for re-verification ... since SHIF's
 * rules have been evolving").
 *
 * rate_bands shape:
 * - paye/nssf: {"bands": [{"up_to": <cumulative cents, null = no cap>, "rate": <decimal>}, ...], "personal_relief_cents"?: int}
 *   up_to is a CUMULATIVE threshold (not a per-band width) - StatutoryCalculationService
 *   walks bands in order, taxing only the slice of the amount within each band.
 * - shif/housing_levy: {"flat_rate": <decimal>, "minimum_cents"?: int}
 * - nita: {"flat_amount_cents": int} - an employer-only flat charge, never a Payslip deduction.
 * - helb: {"flat_rate": 0} by default - **explicitly a placeholder**. Real HELB
 *   deduction is a loan-specific repayment schedule per employee (loan
 *   balance, income band), not a single government-wide rate table: this
 *   seed produces zero HELB deduction for every employee until a tenant
 *   configures real bands, rather than silently fabricating a schedule
 *   this document has no authority to assert.
 */
final class StatutoryDeductionRateSeeder
{
    public static function seed(Tenant $tenant): void
    {
        $now = now();
        $effectiveFrom = '2024-07-01';

        $rows = [
            [
                'deduction_type' => 'paye',
                'rate_bands' => [
                    'bands' => [
                        ['up_to' => 2_400_000, 'rate' => 0.10],
                        ['up_to' => 3_233_300, 'rate' => 0.25],
                        ['up_to' => 50_000_000, 'rate' => 0.30],
                        ['up_to' => 80_000_000, 'rate' => 0.325],
                        ['up_to' => null, 'rate' => 0.35],
                    ],
                    'personal_relief_cents' => 240_000,
                ],
                'employer_rate_bands' => null,
            ],
            [
                'deduction_type' => 'nssf',
                'rate_bands' => [
                    'bands' => [
                        ['up_to' => 800_000, 'rate' => 0.06],
                        ['up_to' => 7_200_000, 'rate' => 0.06],
                    ],
                ],
                'employer_rate_bands' => [
                    'bands' => [
                        ['up_to' => 800_000, 'rate' => 0.06],
                        ['up_to' => 7_200_000, 'rate' => 0.06],
                    ],
                ],
            ],
            [
                'deduction_type' => 'shif',
                'rate_bands' => ['flat_rate' => 0.0275, 'minimum_cents' => 30_000],
                'employer_rate_bands' => null,
            ],
            [
                'deduction_type' => 'housing_levy',
                'rate_bands' => ['flat_rate' => 0.015],
                'employer_rate_bands' => ['flat_rate' => 0.015],
            ],
            [
                'deduction_type' => 'helb',
                'rate_bands' => ['flat_rate' => 0],
                'employer_rate_bands' => null,
            ],
            [
                'deduction_type' => 'nita',
                'rate_bands' => ['flat_amount_cents' => 5_000],
                'employer_rate_bands' => null,
            ],
        ];

        DB::table('statutory_deduction_rates')->insert(array_map(fn ($row) => [
            'tenant_id' => $tenant->getKey(),
            'deduction_type' => $row['deduction_type'],
            'effective_from' => $effectiveFrom,
            'rate_bands' => json_encode($row['rate_bands']),
            'employer_rate_bands' => $row['employer_rate_bands'] ? json_encode($row['employer_rate_bands']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }
}
