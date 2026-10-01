<?php

namespace App\Policies;

use App\Models\ProgressClaim;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class ProgressClaimPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ProgressClaim $progressClaim): bool
    {
        return $user->tenant_id === $progressClaim->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ProgressClaim $progressClaim): bool
    {
        return $user->tenant_id === $progressClaim->tenant_id && $this->canManage($user);
    }
}
