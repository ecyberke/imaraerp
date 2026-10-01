<?php

namespace App\Policies;

use App\Models\ApprovalLimit;
use App\Models\User;

/** Admin-only - an ApprovalLimit defines who may approve what, so only Admin configures it (§11: "Admin — full access, role/permission management"). */
class ApprovalLimitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role?->name === 'admin';
    }

    public function view(User $user, ApprovalLimit $approvalLimit): bool
    {
        return $user->tenant_id === $approvalLimit->tenant_id && $user->role?->name === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'admin';
    }

    public function update(User $user, ApprovalLimit $approvalLimit): bool
    {
        return $user->tenant_id === $approvalLimit->tenant_id && $user->role?->name === 'admin';
    }
}
