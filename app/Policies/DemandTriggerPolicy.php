<?php

namespace App\Policies;

use App\Models\DemandTrigger;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class DemandTriggerPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, DemandTrigger $demandTrigger): bool
    {
        return $user->tenant_id === $demandTrigger->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
