<?php

namespace App\Policies;

use App\Models\ResourceAssignment;
use App\Models\User;

/** §11: "Project Manager — Project, Milestone, Resource Assignment" - a named entity, not a default. */
class ResourceAssignmentPolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, ResourceAssignment $resourceAssignment): bool
    {
        return $user->tenant_id === $resourceAssignment->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, ResourceAssignment $resourceAssignment): bool
    {
        return $user->tenant_id === $resourceAssignment->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
