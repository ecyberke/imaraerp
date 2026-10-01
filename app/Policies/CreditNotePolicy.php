<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class CreditNotePolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $user->tenant_id === $creditNote->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
