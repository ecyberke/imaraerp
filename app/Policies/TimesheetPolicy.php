<?php

namespace App\Policies;

use App\Models\Timesheet;
use App\Models\User;

/**
 * §11 names no role for Timesheet directly - it feeds both HR Manager's
 * payroll calculation and Project Manager/Site Supervisor's project
 * labour cost (§3.11: "project_id ... hours feed both payroll calculation
 * and the project's analytic_account_id"), so all three can submit/
 * approve, flagged the same way every other ungoverned entity has been.
 */
class TimesheetPolicy
{
    private const VIEW_ROLES = ['admin', 'hr_manager', 'project_manager', 'site_supervisor'];

    private const MANAGE_ROLES = ['admin', 'hr_manager', 'project_manager', 'site_supervisor'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Timesheet $timesheet): bool
    {
        return $user->tenant_id === $timesheet->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Timesheet $timesheet): bool
    {
        return $user->tenant_id === $timesheet->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
