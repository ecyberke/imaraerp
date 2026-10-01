<?php

namespace App\Policies;

use App\Models\StockQuarantine;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryOperationsRoles;

class StockQuarantinePolicy
{
    use ChecksInventoryOperationsRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, StockQuarantine $stockQuarantine): bool
    {
        return $user->tenant_id === $stockQuarantine->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canReceive($user);
    }
}
