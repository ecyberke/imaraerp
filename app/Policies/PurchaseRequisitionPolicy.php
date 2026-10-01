<?php

namespace App\Policies;

use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class PurchaseRequisitionPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $user->tenant_id === $purchaseRequisition->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $user->tenant_id === $purchaseRequisition->tenant_id && $this->canManage($user);
    }
}
