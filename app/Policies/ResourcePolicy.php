<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;

/**
 * §11 names "Resource Assignment" under Project Manager but not the
 * bare Resource pool itself - defaulted to the same role set
 * (Admin/Project Manager), flagged the same way every other
 * ungoverned entity in this codebase has been.
 */
class ResourcePolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Resource $resource): bool
    {
        return $user->tenant_id === $resource->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
