<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;

/**
 * §11 names no role for Manufacturing at all - Warehouse ("Stock
 * Ledger, QC disposition, Reservations") is the closest real match
 * since a ProductionOrder is fundamentally a physical stock event (RM
 * out, FG in), with Procurement (already owns planning-adjacent Demand
 * Trigger) and Admin alongside it. Flagged the same way every other
 * ungoverned entity in this codebase has been.
 */
class ProductionOrderPolicy
{
    private const VIEW_ROLES = ['admin', 'warehouse', 'procurement'];

    private const MANAGE_ROLES = ['admin', 'warehouse', 'procurement'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, ProductionOrder $productionOrder): bool
    {
        return $user->tenant_id === $productionOrder->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, ProductionOrder $productionOrder): bool
    {
        return $user->tenant_id === $productionOrder->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
