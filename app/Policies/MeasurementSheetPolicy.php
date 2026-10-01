<?php

namespace App\Policies;

use App\Models\MeasurementSheet;
use App\Models\User;

/** Same ungoverned-entity default as BoqPolicy - see its docblock. */
class MeasurementSheetPolicy
{
    private const VIEW_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    private const MANAGE_ROLES = ['admin', 'sales', 'procurement', 'project_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, MeasurementSheet $measurementSheet): bool
    {
        return $user->tenant_id === $measurementSheet->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, MeasurementSheet $measurementSheet): bool
    {
        return $user->tenant_id === $measurementSheet->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
