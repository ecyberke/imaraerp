<?php

namespace App\Policies;

use App\Models\Subcontract;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class SubcontractPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Subcontract $subcontract): bool
    {
        return $user->tenant_id === $subcontract->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
