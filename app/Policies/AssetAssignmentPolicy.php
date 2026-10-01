<?php

namespace App\Policies;

use App\Models\AssetAssignment;
use App\Models\User;

/**
 * §11: Asset Manager owns AssetAssignment by name; Project Manager also
 * gets visibility/manage here since an assignment is equally a project-
 * cost decision (the equipment-charge posting lands on their project).
 */
class AssetAssignmentPolicy
{
    private const VIEW_ROLES = ['admin', 'asset_manager', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'asset_manager', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, AssetAssignment $assetAssignment): bool
    {
        return $user->tenant_id === $assetAssignment->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, AssetAssignment $assetAssignment): bool
    {
        return $user->tenant_id === $assetAssignment->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
