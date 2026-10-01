<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

/** §11: "Finance — ... Credit Approval, Payments" - a named entity, not a default. */
class PaymentPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->tenant_id && $this->canManage($user);
    }
}
