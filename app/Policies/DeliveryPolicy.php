<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\User;

/**
 * Unlike Lead/SalesOrder, a Delivery is a physical stock event as much as
 * a sales one - Warehouse (§11: "Stock Ledger, QC disposition,
 * Reservations") is added alongside Admin/Sales here, same reasoning
 * procurement's SupplierReturnPolicy already applied to SupplierReturn.
 */
class DeliveryPolicy
{
    private const VIEW_ROLES = ['admin', 'sales', 'warehouse'];

    private const MANAGE_ROLES = ['admin', 'sales', 'warehouse'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $user->tenant_id === $delivery->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Delivery $delivery): bool
    {
        return $user->tenant_id === $delivery->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
