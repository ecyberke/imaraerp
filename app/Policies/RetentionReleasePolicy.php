<?php

namespace App\Policies;

use App\Models\RetentionRelease;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

class RetentionReleasePolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, RetentionRelease $retentionRelease): bool
    {
        return $user->tenant_id === $retentionRelease->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, RetentionRelease $retentionRelease): bool
    {
        return $user->tenant_id === $retentionRelease->tenant_id && $this->canManage($user);
    }
}
