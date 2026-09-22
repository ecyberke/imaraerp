<?php

namespace App\Policies;

use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Policies\Concerns\ChecksFinanceRoles;

/**
 * Admin-only for the posting action would be defensible too (a
 * migration event, not routine finance work), but §11 doesn't
 * distinguish - kept consistent with the rest of finance-billing's
 * ungoverned-entity default.
 */
class OpeningBalanceBatchPolicy
{
    use ChecksFinanceRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, OpeningBalanceBatch $openingBalanceBatch): bool
    {
        return $user->tenant_id === $openingBalanceBatch->tenant_id && $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, OpeningBalanceBatch $openingBalanceBatch): bool
    {
        return $user->tenant_id === $openingBalanceBatch->tenant_id && $this->canManage($user);
    }
}
