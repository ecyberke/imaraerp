<?php

namespace App\Policies;

use App\Models\StockReservation;
use App\Models\User;
use App\Policies\Concerns\ChecksInventoryOperationsRoles;

class StockReservationPolicy
{
    use ChecksInventoryOperationsRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, StockReservation $stockReservation): bool
    {
        return $user->tenant_id === $stockReservation->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canReserve($user);
    }
}
