<?php

namespace App\Policies;

use App\Models\RetentionAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class RetentionAccountPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, RetentionAccount $retentionAccount): bool
    {
        return $user->tenant_id === $retentionAccount->tenant_id && $this->canView($user);
    }
}
