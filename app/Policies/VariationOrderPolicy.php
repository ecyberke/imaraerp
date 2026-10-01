<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VariationOrder;

/**
 * §11 names no role for VariationOrder at all - Project Manager (already
 * owns Project/Milestone, §11) is the closest real match since a VO is
 * fundamentally a project-scoped commercial change, with Admin alongside
 * it. Flagged the same way every other ungoverned entity in this codebase
 * has been (ProductionOrderPolicy, DefectPolicy).
 */
class VariationOrderPolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, VariationOrder $variationOrder): bool
    {
        return $user->tenant_id === $variationOrder->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, VariationOrder $variationOrder): bool
    {
        return $user->tenant_id === $variationOrder->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
