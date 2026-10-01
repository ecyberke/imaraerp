<?php

namespace App\Policies;

use App\Models\Boq;
use App\Models\User;

/**
 * §11 doesn't name any role for BOQ/BOQSection/BOQLine/MeasurementSheet/
 * Markup - unlike Lead/Quotation, BOQ spans more than Sales (boqable is
 * Project or Subcontract, §3.8), so it isn't simply folded into
 * ChecksSalesRoles. Defaulted broadly to Admin/Sales/Procurement/
 * Project Manager (each has a legitimate reason to read or build a BOQ:
 * Sales quotes off it, Procurement's Subcontract references it, PM
 * tracks it against Milestones) - flagged for confirmation the same way
 * every other ungoverned entity in this codebase has been.
 */
class BoqPolicy
{
    private const VIEW_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Boq $boq): bool
    {
        return $user->tenant_id === $boq->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Boq $boq): bool
    {
        return $user->tenant_id === $boq->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
