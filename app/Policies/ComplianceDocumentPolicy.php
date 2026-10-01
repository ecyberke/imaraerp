<?php

namespace App\Policies;

use App\Models\ComplianceDocument;
use App\Models\User;

/** §11 names no role for ComplianceDocument directly - defaults to Procurement/Admin, the same role that owns Subcontract (§3.4), since these documents gate Subcontract's own Progress Claim certification. Flagged the same way every other ungoverned entity in this codebase has been. */
class ComplianceDocumentPolicy
{
    private const VIEW_ROLES = ['admin', 'procurement'];

    private const MANAGE_ROLES = ['admin', 'procurement'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, ComplianceDocument $complianceDocument): bool
    {
        return $user->tenant_id === $complianceDocument->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, ComplianceDocument $complianceDocument): bool
    {
        return $user->tenant_id === $complianceDocument->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
