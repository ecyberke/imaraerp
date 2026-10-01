<?php

namespace App\Policies;

use App\Models\Defect;
use App\Models\User;

/**
 * §11: "Site Supervisor — Project Utilization, Quality Sign Off" is the
 * closest named match for Defect (a quality/workmanship record), alongside
 * Project Manager (owns Project itself) and Admin.
 */
class DefectPolicy
{
    private const VIEW_ROLES = ['admin', 'project_manager', 'site_supervisor'];

    private const MANAGE_ROLES = ['admin', 'project_manager', 'site_supervisor'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, Defect $defect): bool
    {
        return $user->tenant_id === $defect->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Defect $defect): bool
    {
        return $user->tenant_id === $defect->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
