<?php

namespace App\Policies;

use App\Models\LandedCost;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class LandedCostPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, LandedCost $landedCost): bool
    {
        return $user->tenant_id === $landedCost->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
