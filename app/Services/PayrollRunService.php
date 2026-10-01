<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\JournalEntry;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §3.11/§7: a full cycle is generate (one Payslip per EmploymentContract
 * overlapping the run's period, per employee - more than one when a
 * contract change, e.g. a casual-to-permanent conversion, falls
 * mid-period) -> approve (posts the real per-project split, hr-payroll's
 * own exit criterion) -> disburseNetPay. StatutoryRemittance (separate
 * service) is what actually clears the payables this creates.
 */
class PayrollRunService
{
    public function __construct(
        private StatutoryCalculationService $statutory,
        private LedgerPostingService $ledger,
    ) {}

    public function createRun(Tenant $tenant, string $periodStart, string $periodEnd): PayrollRun
    {
        return PayrollRun::create([
            'tenant_id' => $tenant->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
        ]);
    }

    public function generate(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new \DomainException("Cannot generate Payslips for a PayrollRun with status '{$run->status}'.");
        }

        return DB::transaction(function () use ($run) {
            $employees = Employee::where('tenant_id', $run->tenant_id)->where('status', '!=', 'terminated')->get();

            foreach ($employees as $employee) {
                $contracts = EmploymentContract::where('employee_id', $employee->id)
                    ->where('start_date', '<=', $run->period_end)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $run->period_start))
                    ->orderBy('start_date')
                    ->get();

                foreach ($contracts as $contract) {
                    $subStart = $contract->start_date->greaterThan($run->period_start) ? $contract->start_date : $run->period_start;
                    $subEnd = $contract->end_date && $contract->end_date->lessThan($run->period_end) ? $contract->end_date : $run->period_end;

                    $this->createPayslipForContractRange($run, $employee, $contract, $subStart, $subEnd);
                }
            }

            $run->update(['status' => 'processing']);

            return $run->fresh();
        });
    }

    private function createPayslipForContractRange(PayrollRun $run, Employee $employee, EmploymentContract $contract, CarbonInterface $subStart, CarbonInterface $subEnd): Payslip
    {
        if ($contract->basic_salary) {
            $periodTotalDays = $run->period_start->diffInDays($run->period_end) + 1;
            $subRangeDays = $subStart->diffInDays($subEnd) + 1;
            $grossPay = $contract->basic_salary->multiplyByRate(bcdiv((string) $subRangeDays, (string) $periodTotalDays, 6));
            $daysPaidNotWorked = $this->countDaysPaidNotWorked($employee, $subStart, $subEnd);
        } else {
            [$grossPay, $daysPaidNotWorked] = $this->computeHourlyOrDailyGrossPay($employee, $contract, $subStart, $subEnd);
        }

        $taxablePay = $grossPay; // no non-taxable allowances modeled yet (§3.11: defaults to gross_pay)
        $tenant = $employee->tenant;

        $paye = $this->statutory->calculatePaye($tenant, $taxablePay, $run->period_end);
        $nssf = $this->statutory->calculateNssf($tenant, $grossPay, $run->period_end);
        $shif = $this->statutory->calculateShif($tenant, $grossPay, $run->period_end);
        $housing = $this->statutory->calculateHousingLevy($tenant, $grossPay, $run->period_end);
        $helb = $this->statutory->calculateHelb($tenant, $grossPay, $run->period_end);

        $netPay = $grossPay
            ->sub($paye['amount'])->sub($nssf['employee'])->sub($shif)
            ->sub($housing['employee'])->sub($helb);

        return Payslip::create([
            'tenant_id' => $run->tenant_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'employment_contract_id' => $contract->id,
            'period_start' => $subStart,
            'period_end' => $subEnd,
            'gross_pay_cents' => $grossPay,
            'taxable_pay_cents' => $taxablePay,
            'days_paid_not_worked' => $daysPaidNotWorked,
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
    }

    /** @return array{0: Money, 1: float} [grossPay, daysPaidNotWorked] */
    private function computeHourlyOrDailyGrossPay(Employee $employee, EmploymentContract $contract, CarbonInterface $subStart, CarbonInterface $subEnd): array
    {
        $timesheets = Timesheet::where('employee_id', $employee->id)->where('status', 'approved')
            ->whereBetween('date', [$subStart->toDateString(), $subEnd->toDateString()])->get();

        // Overtime always has an hourly basis to multiply against, even
        // under a flat daily_rate contract (daily_rate / 8 standing in
        // for the hourly-equivalent) - normal pay for a daily_rate
        // contract is the flat rate per worked day, never hours x
        // derived-hourly-rate, so a casual logging 10 hours one day
        // isn't accidentally paid 1.25 days for ordinary hours.
        $overtimeHourlyBasis = $contract->hourly_rate ?? $contract->daily_rate->multiply(bcdiv('1', '8', 6));

        $gross = Money::zero();
        foreach ($timesheets as $timesheet) {
            if ($contract->daily_rate && (float) $timesheet->hours_normal > 0) {
                $gross = $gross->add($contract->daily_rate);
            } elseif ($contract->hourly_rate) {
                $gross = $gross->add($contract->hourly_rate->multiply((string) $timesheet->hours_normal));
            }

            if ((float) ($timesheet->hours_overtime_weekday ?? 0) > 0) {
                $gross = $gross->add($overtimeHourlyBasis->multiply((string) $timesheet->hours_overtime_weekday)->multiply((string) $contract->overtime_multiplier_weekday));
            }
            if ((float) ($timesheet->hours_overtime_restday ?? 0) > 0) {
                $gross = $gross->add($overtimeHourlyBasis->multiply((string) $timesheet->hours_overtime_restday)->multiply((string) $contract->overtime_multiplier_restday));
            }
        }

        $daysPaidNotWorked = $this->countDaysPaidNotWorked($employee, $subStart, $subEnd);
        // §3.11: "for daily-rate/casual staff it's a real pay component
        // with no corresponding Timesheet entry." No monetizable
        // equivalent exists for a pure hourly_rate contract with no
        // daily_rate - the field is still recorded for the P9A/record,
        // just doesn't add pay in that case.
        if ($daysPaidNotWorked > 0 && $contract->daily_rate) {
            $gross = $gross->add($contract->daily_rate->multiply((string) $daysPaidNotWorked));
        }

        return [$gross, $daysPaidNotWorked];
    }

    private function countDaysPaidNotWorked(Employee $employee, CarbonInterface $start, CarbonInterface $end): float
    {
        $leaveDays = LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())
            ->get()
            ->sum(function (LeaveRequest $leave) use ($start, $end) {
                $s = $leave->start_date->greaterThan($start) ? $leave->start_date : $start;
                $e = $leave->end_date->lessThan($end) ? $leave->end_date : $end;

                return $s->diffInDays($e) + 1;
            });

        $holidayDays = PublicHoliday::where('tenant_id', $employee->tenant_id)->where('is_paid', true)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->count();

        return $leaveDays + $holidayDays;
    }

    public function approve(PayrollRun $run, User $approvedBy): PayrollRun
    {
        if ($run->status !== 'processing') {
            throw new \DomainException("Cannot approve a PayrollRun with status '{$run->status}'.");
        }

        return DB::transaction(function () use ($run, $approvedBy) {
            $payslips = $run->payslips()->get();
            $tenant = $run->tenant;

            $grossPay = Money::sum(...$payslips->map(fn (Payslip $p) => $p->gross_pay)->all());
            $employeeDeductions = [
                'paye' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->paye_amount)->all()),
                'nssf' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->nssf_amount)->all()),
                'shif' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->shif_amount)->all()),
                'housing' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->housing_levy_amount)->all()),
                'helb' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->helb_amount)->all()),
            ];
            $employerContributions = [
                'nssf' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->employer_nssf_amount)->all()),
                'housing' => Money::sum(...$payslips->map(fn (Payslip $p) => $p->employer_housing_levy_amount)->all()),
            ];
            $otherDeductions = Money::sum(...$payslips->map(fn (Payslip $p) => $p->other_deductions)->all());
            $nita = $this->statutory->calculateNita($tenant, $run->period_end)->multiply((string) $payslips->count());

            $entryInput = $this->ledger->postPayrollRun(
                payrollRunReference: (string) $run->id,
                grossPay: $grossPay,
                employeeDeductions: $employeeDeductions,
                employerContributions: $employerContributions,
                otherDeductions: $otherDeductions,
                nitaAmount: $nita,
                grossPayByAnalyticAccountCode: $this->computeAnalyticSplit($payslips),
            );
            $journalEntry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), $approvedBy, $run->id);

            $run->update([
                'status' => 'approved', 'approved_by' => $approvedBy->id,
                'journal_entry_id' => $journalEntry->id, 'run_date' => BusinessTime::today(),
            ]);
            $payslips->each(fn (Payslip $p) => $p->update(['status' => 'approved']));

            return $run->fresh();
        });
    }

    /**
     * §7/hr-payroll's own exit criterion: "Gross pay splits into one
     * JournalLine per AnalyticAccount a Timesheet touched that period."
     * Each Payslip's own gross_pay is split proportionally by that
     * employee's approved Timesheet hours in their sub-period, by
     * project; an employee with no project-tagged hours that period
     * (including anyone with no Timesheets at all, e.g. straight
     * monthly-salaried staff) has their whole gross_pay bucketed
     * untagged ('').
     *
     * @return array<string, Money>
     */
    private function computeAnalyticSplit(Collection $payslips): array
    {
        $split = [];

        foreach ($payslips as $payslip) {
            $timesheets = Timesheet::where('employee_id', $payslip->employee_id)->where('status', 'approved')
                ->whereBetween('date', [$payslip->period_start->toDateString(), $payslip->period_end->toDateString()])
                ->with('project.analyticAccount')
                ->get();

            $hoursByCode = [];
            foreach ($timesheets as $timesheet) {
                $hours = (float) $timesheet->hours_normal + (float) ($timesheet->hours_overtime_weekday ?? 0) + (float) ($timesheet->hours_overtime_restday ?? 0);
                if ($hours <= 0) {
                    continue;
                }
                $code = $timesheet->project?->analyticAccount?->cost_code ?? '';
                $hoursByCode[$code] = ($hoursByCode[$code] ?? 0) + $hours;
            }

            if (empty($hoursByCode)) {
                $split[''] = ($split[''] ?? Money::zero())->add($payslip->gross_pay);

                continue;
            }

            foreach ($this->splitProportionally($payslip->gross_pay, $hoursByCode) as $code => $amount) {
                $split[$code] = ($split[$code] ?? Money::zero())->add($amount);
            }
        }

        return $split;
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, Money>
     */
    private function splitProportionally(Money $total, array $weights): array
    {
        $totalWeight = array_sum($weights);
        $keys = array_keys($weights);
        $result = [];
        $running = Money::zero();

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $result[$key] = $total->sub($running); // last bucket absorbs any rounding remainder
            } else {
                $share = $total->multiplyByRate($weights[$key] / $totalWeight);
                $result[$key] = $share;
                $running = $running->add($share);
            }
        }

        return $result;
    }

    public function disburseNetPay(PayrollRun $run): JournalEntry
    {
        if ($run->status !== 'approved') {
            throw new \DomainException("Cannot disburse net pay for a PayrollRun with status '{$run->status}'.");
        }

        return DB::transaction(function () use ($run) {
            $totalNetPay = Money::sum(...$run->payslips->map(fn (Payslip $p) => $p->net_pay)->all());

            $entryInput = $this->ledger->postNetPayDisbursed((string) $run->id, $totalNetPay);
            $journalEntry = $this->ledger->commit($run->tenant, $entryInput, BusinessTime::today(), null, $run->id);

            $run->update(['status' => 'paid']);
            $run->payslips->each(fn (Payslip $p) => $p->update(['status' => 'paid']));

            return $journalEntry;
        });
    }
}
