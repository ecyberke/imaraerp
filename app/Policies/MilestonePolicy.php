<?php

namespace App\Policies;

use App\Models\Milestone;
use App\Models\User;

/**
 * §11: "Project Manager — Project, Milestone, Resource Assignment" owns
 * full management (create, BOQ-line allocation, invoicing, closing).
 * "Site Supervisor — Project Utilization, Quality Sign Off" is the exact
 * match for the utilized/signed_off/rework_required transitions, so
 * signOff() is a separate, narrower ability rather than folded into
 * update().
 */
class MilestonePolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager', 'site_supervisor'];

    private const MANAGE_ROLES = ['admin', 'project_manager'];

    private const SIGN_OFF_ROLES = ['admin', 'project_manager', 'site_supervisor'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Milestone $milestone): bool
    {
        return $user->tenant_id === $milestone->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return $user->tenant_id === $milestone->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function signOff(User $user, Milestone $milestone): bool
    {
        return $user->tenant_id === $milestone->tenant_id && in_array($user->role?->name, self::SIGN_OFF_ROLES, true);
    }
}
