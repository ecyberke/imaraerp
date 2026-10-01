<?php

namespace App\Policies;

use App\Models\User;

/** Admin-only - third-party API secrets, same bar as ApprovalLimitPolicy. */
class IntegrationCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'admin';
    }
}
