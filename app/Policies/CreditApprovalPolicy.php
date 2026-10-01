<?php

namespace App\Policies;

use App\Models\CreditApproval;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

/** §11: "Finance — ... Credit Approval, Payments" - a named entity, not a default. */
class CreditApprovalPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CreditApproval $creditApproval): bool
    {
        return $user->tenant_id === $creditApproval->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, CreditApproval $creditApproval): bool
    {
        return $user->tenant_id === $creditApproval->tenant_id && $this->canManage($user);
    }
}
