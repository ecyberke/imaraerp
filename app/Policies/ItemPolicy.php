<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryMasterDataRoles;

class ItemPolicy
{
    use ChecksInventoryMasterDataRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Item $item): bool
    {
        return $user->tenant_id === $item->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Item $item): bool
    {
        return $user->tenant_id === $item->tenant_id && $this->canManage($user);
    }

    public function delete(User $user, Item $item): bool
    {
        return $user->tenant_id === $item->tenant_id && $this->isAdmin($user);
    }
}
