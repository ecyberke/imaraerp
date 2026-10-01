<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

/** §11: "HR Manager — ... LeaveRequest approval." */
class LeaveRequestPolicy
{
    private const VIEW_ROLES = ['admin', 'hr_manager'];

    private const MANAGE_ROLES = ['admin', 'hr_manager'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->tenant_id === $leaveRequest->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->tenant_id === $leaveRequest->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
