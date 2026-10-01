<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WriteOff;
use App\Policies\Concerns\ChecksFinanceRoles;

class WriteOffPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, WriteOff $writeOff): bool
    {
        return $user->tenant_id === $writeOff->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }
}
