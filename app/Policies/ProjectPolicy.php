<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/** §11: "Project Manager — Project, Milestone, Resource Assignment" - a named entity, not a default. */
class ProjectPolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager', 'site_supervisor'];

    private const MANAGE_ROLES = ['admin', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->tenant_id === $project->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->tenant_id === $project->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
