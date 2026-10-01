<?php

namespace App\Policies;

use App\Models\SupplierReturn;
use App\Models\User;

/**
 * Unlike most of this branch's entities, a supplier return is a physical
 * stock event as much as a procurement one - Warehouse (§11: "Stock
 * Ledger, QC disposition, Reservations") is added alongside
 * Admin/Procurement here, not just the ChecksProcurementRoles default.
 */
class SupplierReturnPolicy
{
    private const VIEW_ROLES = ['admin', 'procurement', 'warehouse'];

    private const MANAGE_ROLES = ['admin', 'procurement', 'warehouse'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, SupplierReturn $supplierReturn): bool
    {
        return $user->tenant_id === $supplierReturn->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
