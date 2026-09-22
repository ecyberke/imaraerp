<?php

namespace App\Policies;

use App\Models\CapitalMovement;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class CapitalMovementPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CapitalMovement $capitalMovement): bool
    {
        return $user->tenant_id === $capitalMovement->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
