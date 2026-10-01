<?php

namespace App\Policies;

use App\Models\EquipmentHireContract;
use App\Models\User;

/** §11: Asset Manager owns EquipmentHireContract by name alongside the owned-plant entities. */
class EquipmentHireContractPolicy
{
    private const VIEW_ROLES = ['admin', 'asset_manager', 'project_manager', 'procurement'];

    private const MANAGE_ROLES = ['admin', 'asset_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, EquipmentHireContract $equipmentHireContract): bool
    {
        return $user->tenant_id === $equipmentHireContract->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, EquipmentHireContract $equipmentHireContract): bool
    {
        return $user->tenant_id === $equipmentHireContract->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
