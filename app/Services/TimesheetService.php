<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;

/**
 * §3.11: "Where project_id is set, hours feed both payroll calculation
 * and the project's analytic_account_id - labour cost lands on the right
 * project automatically." Casual-to-permanent conversion tracking
 * (Employment Act 2007 s.37) happens here, on approval - an unapproved
 * Timesheet is a claim, not yet a worked day.
 */
class TimesheetService
{
    /** Employment Act 2007 s.37: "one month continuously, or an equivalent of three months with breaks." Only the cumulative variant is tracked here - see the employees migration's own docblock. */
    private const CASUAL_CONVERSION_THRESHOLD_DAYS = 90;

    public function submit(Employee $employee, array $data): Timesheet
    {
        return Timesheet::create([
            ...$data,
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'status' => 'submitted',
        ]);
    }

    public function approve(Timesheet $timesheet, User $approvedBy): Timesheet
    {
        if ($timesheet->status !== 'submitted') {
            throw new \DomainException("Cannot approve a Timesheet with status '{$timesheet->status}'.");
        }

        $timesheet->update(['status' => 'approved', 'approved_by' => $approvedBy->id]);

        $employee = $timesheet->employee;
        if ($employee->employment_type === 'casual' && (float) $timesheet->hours_normal > 0) {
            $this->incrementCasualDays($employee);
        }

        return $timesheet->fresh();
    }

    public function reject(Timesheet $timesheet): Timesheet
    {
        if ($timesheet->status !== 'submitted') {
            throw new \DomainException("Cannot reject a Timesheet with status '{$timesheet->status}'.");
        }

        $timesheet->update(['status' => 'rejected']);

        return $timesheet->fresh();
    }

    private function incrementCasualDays(Employee $employee): void
    {
        $newTotal = ($employee->cumulative_casual_days_worked ?? 0) + 1;

        $employee->update([
            'cumulative_casual_days_worked' => $newTotal,
            'casual_conversion_due' => $newTotal >= self::CASUAL_CONVERSION_THRESHOLD_DAYS,
        ]);
    }
}
