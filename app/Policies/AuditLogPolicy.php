<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Architecture §1.1: "access to AuditLog itself needs its own Policy, not
 * blanket admin-by-convention." Admin-only for now, stated explicitly in
 * code rather than left as an unenforced assumption - AuditLog entries for
 * future Payslip changes carry salary in old_values/new_values, so this
 * gets tightened (not loosened) as more entities start writing to it.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'admin';
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->role?->name === 'admin' && $user->tenant_id === $auditLog->tenant_id;
    }
}
