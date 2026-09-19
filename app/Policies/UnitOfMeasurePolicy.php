<?php

namespace App\Policies;

use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryMasterDataRoles;

class UnitOfMeasurePolicy
{
    use ChecksInventoryMasterDataRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, UnitOfMeasure $unitOfMeasure): bool
    {
        return $user->tenant_id === $unitOfMeasure->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, UnitOfMeasure $unitOfMeasure): bool
    {
        return $user->tenant_id === $unitOfMeasure->tenant_id && $this->canManage($user);
    }

    public function delete(User $user, UnitOfMeasure $unitOfMeasure): bool
    {
        return $user->tenant_id === $unitOfMeasure->tenant_id && $this->isAdmin($user);
    }
}
