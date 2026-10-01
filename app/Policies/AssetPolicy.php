<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

/** §11: "Asset Manager — Asset, AssetComponent, AssetRevaluation, AssetDisposal, AssetAssignment, EquipmentHireContract, ComplianceDocument." */
class AssetPolicy
{
    private const VIEW_ROLES = ['admin', 'asset_manager'];

    private const MANAGE_ROLES = ['admin', 'asset_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->tenant_id === $asset->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->tenant_id === $asset->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
