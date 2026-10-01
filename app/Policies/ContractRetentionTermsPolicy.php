<?php

namespace App\Policies;

use App\Models\ContractRetentionTerms;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class ContractRetentionTermsPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, ContractRetentionTerms $contractRetentionTerms): bool
    {
        return $user->tenant_id === $contractRetentionTerms->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
