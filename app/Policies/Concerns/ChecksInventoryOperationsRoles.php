<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * §11 explicitly names Warehouse's scope: "Stock Ledger, QC disposition,
 * Reservations." Procurement (goods arriving) and Sales (reserving
 * finished stock) are the other two roles that legitimately touch these
 * flows day to day; Admin always can.
 */
trait ChecksInventoryOperationsRoles
{
    private const VIEW_ROLES = ['admin', 'warehouse', 'procurement', 'sales'];

    private const RECEIVING_ROLES = ['admin', 'warehouse', 'procurement'];

    private const QC_ROLES = ['admin', 'warehouse'];

    private const RESERVATION_ROLES = ['admin', 'warehouse', 'sales', 'procurement'];

    protected function canView(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    protected function canReceive(User $user): bool
    {
        return in_array($user->role?->name, self::RECEIVING_ROLES, true);
    }

    protected function canDisposition(User $user): bool
    {
        return in_array($user->role?->name, self::QC_ROLES, true);
    }

    protected function canReserve(User $user): bool
    {
        return in_array($user->role?->name, self::RESERVATION_ROLES, true);
    }

    protected function isAdmin(User $user): bool
    {
        return $user->role?->name === 'admin';
    }
}
