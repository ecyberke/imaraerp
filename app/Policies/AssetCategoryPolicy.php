<?php

namespace App\Policies;

use App\Models\AssetCategory;
use App\Models\User;

class AssetCategoryPolicy
{
    private const VIEW_ROLES = ['admin', 'asset_manager'];

    private const MANAGE_ROLES = ['admin', 'asset_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, AssetCategory $assetCategory): bool
    {
        return $user->tenant_id === $assetCategory->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
