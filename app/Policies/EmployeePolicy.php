<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/** §11: "HR Manager — Employee, EmploymentContract, LeaveRequest approval, PayrollRun, Payslip, P9A/P10." */
class EmployeePolicy
{
    private const VIEW_ROLES = ['admin', 'hr_manager'];

    private const MANAGE_ROLES = ['admin', 'hr_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->tenant_id === $employee->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->tenant_id === $employee->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
