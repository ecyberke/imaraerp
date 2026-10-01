<?php

namespace App\Policies;

use App\Models\DebitNote;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class DebitNotePolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, DebitNote $debitNote): bool
    {
        return $user->tenant_id === $debitNote->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
