<?php

namespace App\Policies;

use App\Models\SalesReturn;
use App\Models\User;

/**
 * Same reasoning as DeliveryPolicy/procurement's SupplierReturnPolicy -
 * a physical restocking event, not purely a sales one.
 */
class SalesReturnPolicy
{
    private const VIEW_ROLES = ['admin', 'sales', 'warehouse'];

    private const MANAGE_ROLES = ['admin', 'sales', 'warehouse'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, SalesReturn $salesReturn): bool
    {
        return $user->tenant_id === $salesReturn->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
