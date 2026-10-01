<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §3.11: "Non-overlap enforced - the system refuses a second contract on
 * the same employee with overlapping active date ranges." Checked at the
 * service layer (not a DB constraint - see the migration's own docblock)
 * against every OTHER contract for the same employee, inclusive on both
 * ends (a contract ending 2026-01-15 and one starting 2026-01-15 do
 * overlap on that day).
 */
class EmploymentContractService
{
    public function create(Employee $employee, array $data): EmploymentContract
    {
        if (($data['basic_salary'] ?? null) !== null && (($data['hourly_rate'] ?? null) !== null || ($data['daily_rate'] ?? null) !== null)) {
            throw new \InvalidArgumentException('basic_salary is mutually exclusive with hourly_rate/daily_rate.');
        }

        $this->assertNoOverlap($employee, $data['start_date'], $data['end_date'] ?? null);

        return DB::transaction(fn () => EmploymentContract::create([
            ...$data,
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'basic_salary_cents' => isset($data['basic_salary']) ? Money::fromMajor($data['basic_salary']) : null,
            'hourly_rate_cents' => isset($data['hourly_rate']) ? Money::fromMajor($data['hourly_rate']) : null,
            'daily_rate_cents' => isset($data['daily_rate']) ? Money::fromMajor($data['daily_rate']) : null,
        ]));
    }

    /**
     * §3.11: "a promotion or contract-type change means the old
     * contract's end_date is set and the new one's start_date begins the
     * next day." A convenience that performs both halves atomically
     * rather than requiring the caller to remember the order.
     */
    public function promote(EmploymentContract $currentContract, array $newContractData): EmploymentContract
    {
        return DB::transaction(function () use ($currentContract, $newContractData) {
            $newStart = $newContractData['start_date'];
            $currentContract->update(['end_date' => Carbon::parse($newStart)->subDay()->toDateString()]);

            return $this->create($currentContract->employee, $newContractData);
        });
    }

    private function assertNoOverlap(Employee $employee, string $startDate, ?string $endDate): void
    {
        $overlapping = EmploymentContract::where('employee_id', $employee->id)
            ->where('start_date', '<=', $endDate ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $startDate))
            ->exists();

        if ($overlapping) {
            throw new \DomainException("Employee #{$employee->id} already has an EmploymentContract overlapping this date range.");
        }
    }
}
