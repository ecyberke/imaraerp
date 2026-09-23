<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class BankAccountPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $user->tenant_id === $bankAccount->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
