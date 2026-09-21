<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksSalesRoles;

class SalesOrderPolicy
{
    use ChecksSalesRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return $user->tenant_id === $salesOrder->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, SalesOrder $salesOrder): bool
    {
        return $user->tenant_id === $salesOrder->tenant_id && $this->canManage($user);
    }

    public function assessFeasibility(User $user, SalesOrder $salesOrder): bool
    {
        return $user->tenant_id === $salesOrder->tenant_id && $this->canManage($user);
    }

    public function reserveStock(User $user, SalesOrder $salesOrder): bool
    {
        return $user->tenant_id === $salesOrder->tenant_id && $this->canManage($user);
    }
}
