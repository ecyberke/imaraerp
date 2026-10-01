<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\P10;
use App\Models\P9A;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Support\Money;

/**
 * §3.11: "generated from the year's Payslips rather than recalculated ad
 * hoc at filing time" (P9A) / "cached employer-level monthly PAYE return
 * summary across all Payslips in that period's PayrollRuns" (P10).
 */
class TaxComplianceReportService
{
    public function generateP9A(Employee $employee, int $year): P9A
    {
        $payslips = Payslip::where('employee_id', $employee->id)
            ->whereYear('period_start', $year)
            ->get();

        return P9A::updateOrCreate(
            ['employee_id' => $employee->id, 'year' => $year],
            [
                'tenant_id' => $employee->tenant_id,
                'taxable_pay_cents' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->taxable_pay)->all()),
                'total_paye_cents' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->paye_amount)->all()),
                'personal_relief_applied_cents' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->personal_relief_applied)->all()),
            ],
        );
    }

    public function generateP10(Tenant $tenant, int $month, int $year): P10
    {
        $payslips = Payslip::where('tenant_id', $tenant->id)
            ->whereYear('period_start', $year)
            ->whereMonth('period_start', $month)
            ->get();

        return P10::updateOrCreate(
            ['tenant_id' => $tenant->id, 'month' => $month, 'year' => $year],
            [
                'employee_count' => $payslips->pluck('employee_id')->unique()->count(),
                'total_gross_pay_cents' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->gross_pay)->all()),
                'total_paye_cents' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->paye_amount)->all()),
            ],
        );
    }
}
