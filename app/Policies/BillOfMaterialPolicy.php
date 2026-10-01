<?php

namespace App\Policies;

use App\Models\BillOfMaterial;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryMasterDataRoles;

class BillOfMaterialPolicy
{
    use ChecksInventoryMasterDataRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, BillOfMaterial $billOfMaterial): bool
    {
        return $user->tenant_id === $billOfMaterial->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, BillOfMaterial $billOfMaterial): bool
    {
        return $user->tenant_id === $billOfMaterial->tenant_id && $this->canManage($user);
    }

    public function delete(User $user, BillOfMaterial $billOfMaterial): bool
    {
        return $user->tenant_id === $billOfMaterial->tenant_id && $this->isAdmin($user);
    }
}
