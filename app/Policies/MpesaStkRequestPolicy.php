<?php

namespace App\Policies;

use App\Models\MpesaStkRequest;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

/** Initiating an M-Pesa collection is a Payment-adjacent action - same role gate as PaymentPolicy. */
class MpesaStkRequestPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, MpesaStkRequest $mpesaStkRequest): bool
    {
        return $user->tenant_id === $mpesaStkRequest->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
