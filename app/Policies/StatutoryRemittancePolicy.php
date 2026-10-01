<?php

namespace App\Policies;

use App\Models\StatutoryRemittance;
use App\Models\User;

/** Mirrors PayrollRunPolicy's role set - the same HR Manager/Admin closing step on a payroll cycle. */
class StatutoryRemittancePolicy
{
    private const VIEW_ROLES = ['admin', 'hr_manager'];

    private const MANAGE_ROLES = ['admin', 'hr_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, StatutoryRemittance $statutoryRemittance): bool
    {
        return $user->tenant_id === $statutoryRemittance->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, StatutoryRemittance $statutoryRemittance): bool
    {
        return $user->tenant_id === $statutoryRemittance->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
