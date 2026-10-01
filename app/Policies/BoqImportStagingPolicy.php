<?php

namespace App\Policies;

use App\Models\BoqImportStaging;
use App\Models\User;

/** Same ungoverned-entity default as BoqPolicy - see its docblock. */
class BoqImportStagingPolicy
{
    private const VIEW_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, BoqImportStaging $staging): bool
    {
        return $user->tenant_id === $staging->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, BoqImportStaging $staging): bool
    {
        return $user->tenant_id === $staging->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
