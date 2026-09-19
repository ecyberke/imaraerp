<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Shared role check for Category/UnitOfMeasure/Item/BillOfMaterial -
 * §11 names no owning role for any of these either. Reasonable default:
 * Admin/Procurement/Warehouse can view (both touch inventory data day
 * to day); Admin/Procurement can create/update (Warehouse works with
 * items, doesn't define them); delete is Admin-only.
 */
trait ChecksInventoryMasterDataRoles
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
