<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * §3.11: "EmploymentContract.date_of_exit triggers a FinalSettlement ...
 * Prorates the terminating month's salary to days_worked_this_period
 * rather than paying a full month, and computes leave encashment against
 * LeaveType.days_entitled_per_year less days already taken."
 */
class FinalSettlementService
{
    public function __construct(private StatutoryCalculationService $statutory) {}

    public function settle(Employee $employee, PayrollRun $run, CarbonInterface $lastWorkingDay, bool $encashLeave = true): FinalSettlement
    {
        $contract = $employee->activeContract()->first();
        if (! $contract) {
            throw new \DomainException("Employee #{$employee->id} has no active EmploymentContract to settle against.");
        }

        return DB::transaction(function () use ($employee, $run, $lastWorkingDay, $contract, $encashLeave) {
            $periodTotalDays = $run->period_start->diffInDays($run->period_end) + 1;
            $daysWorkedThisPeriod = $run->period_start->diffInDays($lastWorkingDay) + 1;

            $proratedGross = $contract->basic_salary
                ? $contract->basic_salary->multiplyByRate(bcdiv((string) $daysWorkedThisPeriod, (string) $periodTotalDays, 6))
                : Money::zero(); // daily/hourly staff are already paid only for days actually worked - nothing to prorate

            $accruedLeaveDays = $this->accruedLeaveDays($employee, $lastWorkingDay);
            $leaveEncashment = null;
            if ($encashLeave && $accruedLeaveDays > 0) {
                $dailyEquivalent = $contract->daily_rate ?? ($contract->basic_salary ? $contract->basic_salary->multiply(bcdiv('1', '30', 6)) : Money::zero());
                $leaveEncashment = $dailyEquivalent->multiply((string) $accruedLeaveDays);
            }

            $grossPay = $proratedGross->add($leaveEncashment ?? Money::zero());
            $paye = $this->statutory->calculatePaye($employee->tenant, $grossPay, $lastWorkingDay);
            $nssf = $this->statutory->calculateNssf($employee->tenant, $grossPay, $lastWorkingDay);
            $shif = $this->statutory->calculateShif($employee->tenant, $grossPay, $lastWorkingDay);
            $housing = $this->statutory->calculateHousingLevy($employee->tenant, $grossPay, $lastWorkingDay);
            $helb = $this->statutory->calculateHelb($employee->tenant, $grossPay, $lastWorkingDay);
            $netPay = $grossPay->sub($paye['amount'])->sub($nssf['employee'])->sub($shif)->sub($housing['employee'])->sub($helb);

            $payslip = Payslip::create([
                'tenant_id' => $employee->tenant_id,
                'payroll_run_id' => $run->id,
                'employee_id' => $employee->id,
                'employment_contract_id' => $contract->id,
                'period_start' => $run->period_start,
                'period_end' => $lastWorkingDay,
                'gross_pay_cents' => $grossPay,
                'taxable_pay_cents' => $grossPay,
                'days_paid_not_worked' => 0,
                'paye_amount_cents' => $paye['amount'],
                'personal_relief_applied_cents' => $paye['personal_relief'],
                'nssf_amount_cents' => $nssf['employee'],
                'shif_amount_cents' => $shif,
                'housing_levy_amount_cents' => $housing['employee'],
                'helb_amount_cents' => $helb,
                'other_deductions_cents' => Money::zero(),
                'employer_nssf_amount_cents' => $nssf['employer'],
                'employer_housing_levy_amount_cents' => $housing['employer'],
                'net_pay_cents' => $netPay,
                'status' => 'draft',
            ]);

            $settlement = FinalSettlement::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'payslip_id' => $payslip->id,
                'last_working_day' => $lastWorkingDay,
                'days_worked_this_period' => $daysWorkedThisPeriod,
                'accrued_leave_days' => $accruedLeaveDays,
                'leave_encashment_amount_cents' => $leaveEncashment,
                'final_paye_amount_cents' => $paye['amount'],
            ]);

            $contract->update(['end_date' => $lastWorkingDay]);
            $employee->update(['status' => 'terminated', 'date_of_exit' => $lastWorkingDay]);

            return $settlement->fresh();
        });
    }

    /**
     * Entitlement accrues evenly across the calendar year, less annual
     * leave already taken this year - a simplified but defensible
     * proration (real policies vary on whether entitlement accrues from
     * date_of_hire or calendar-year start; this uses calendar-year, the
     * simpler and more common default, flagged as a choice rather than
     * silently assumed).
     */
    private function accruedLeaveDays(Employee $employee, CarbonInterface $lastWorkingDay): float
    {
        $annualLeaveType = LeaveType::where('tenant_id', $employee->tenant_id)->where('name', 'annual')->first();
        if (! $annualLeaveType) {
            return 0;
        }

        $yearStart = $lastWorkingDay->copy()->startOfYear();
        $daysElapsedThisYear = $yearStart->diffInDays($lastWorkingDay) + 1;
        $daysInYear = $lastWorkingDay->copy()->endOfYear()->dayOfYear;

        $entitlementAccrued = $annualLeaveType->days_entitled_per_year * ($daysElapsedThisYear / $daysInYear);

        $daysTaken = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $annualLeaveType->id)
            ->where('status', 'approved')
            ->where('start_date', '>=', $yearStart->toDateString())
            ->where('start_date', '<=', $lastWorkingDay->toDateString())
            ->get()
            ->sum(fn (LeaveRequest $r) => $r->days());

        return max(0, round($entitlementAccrued - $daysTaken, 2));
    }
}
