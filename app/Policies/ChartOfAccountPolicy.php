<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

/** §11: "Finance — Chart of Accounts, Journal Entries, Credit Approval, Payments." */
class ChartOfAccountPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }
}
