<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * §11: "Procurement — Demand Trigger, PR, PO, GRN, Supplier Performance."
 * Subcontract/ProgressClaim/LandedCost/SupplierPayment/SupplierReturn
 * aren't explicitly named under any role - defaulted to Admin/Procurement
 * here, flagged for confirmation the same way master-data's ungoverned
 * entities were.
 */
trait ChecksProcurementRoles
{
    private const VIEW_ROLES = ['admin', 'procurement', 'warehouse'];

    private const MANAGE_ROLES = ['admin', 'procurement'];

    protected function canView(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    protected function canManage(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    protected function isAdmin(User $user): bool
    {
        return $user->role?->name === 'admin';
    }
}
