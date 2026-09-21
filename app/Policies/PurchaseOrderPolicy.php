<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class PurchaseOrderPolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->tenant_id === $purchaseOrder->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->tenant_id === $purchaseOrder->tenant_id && $this->canManage($user);
    }
}
