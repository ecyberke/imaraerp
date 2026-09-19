<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use App\Policies\Concerns\ChecksInventoryOperationsRoles;

class WarehousePolicy
{
    use ChecksInventoryOperationsRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->tenant_id === $warehouse->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->tenant_id === $warehouse->tenant_id && $this->isAdmin($user);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $user->tenant_id === $warehouse->tenant_id && $this->isAdmin($user);
    }
}
