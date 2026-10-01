<?php

namespace App\Policies;

use App\Models\PayrollRun;
use App\Models\User;

/**
 * §11/§12 item #9: "HR Manager retains full PayrollRun/Payslip access ...
 * Finance gets read access to aggregate JournalLine postings only, never
 * individual Payslip records." Reused for Payslip/P9A/P10/
 * StatutoryRemittance/FinalSettlement access too (PayrollRunController's
 * own cluster of actions) rather than one policy per entity, the same
 * "no separate policy for a report-shaped entity" convention
 * ReportController already follows.
 *
 * True employee self-service ("an employee's own Payslip remains visible
 * to themselves," §12 item #9) isn't implemented here - there is no link
 * anywhere in this codebase from an Employee row to the User account
 * that would log in and view it. Flagged, not silently assumed; building
 * it means deciding how an Employee ever gets a User account in the
 * first place, which is its own decision outside this branch's scope.
 */
class PayrollRunPolicy
{
    private const VIEW_ROLES = ['admin', 'hr_manager'];

    private const MANAGE_ROLES = ['admin', 'hr_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, PayrollRun $payrollRun): bool
    {
        return $user->tenant_id === $payrollRun->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, PayrollRun $payrollRun): bool
    {
        return $user->tenant_id === $payrollRun->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
