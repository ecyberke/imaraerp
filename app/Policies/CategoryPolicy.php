<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryMasterDataRoles;

class CategoryPolicy
{
    use ChecksInventoryMasterDataRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $user->tenant_id === $category->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $user->tenant_id === $category->tenant_id && $this->canManage($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->tenant_id === $category->tenant_id && $this->isAdmin($user);
    }
}
