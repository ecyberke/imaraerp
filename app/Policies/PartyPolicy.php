<?php

namespace App\Policies;

use App\Models\Party;
use App\Models\User;

/**
 * §11 names no owning role for Party - customers, suppliers and
 * contractors are all "Party", and both Sales (customers) and
 * Procurement (suppliers/contractors) legitimately create them.
 * Reasonable default, flagged for confirmation rather than assumed:
 * Admin/Sales/Procurement can view and manage; delete is Admin-only
 * (a Party with transaction history shouldn't disappear casually).
 */
class PartyPolicy
{
    private const MANAGE_ROLES = ['admin', 'sales', 'procurement'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function view(User $user, Party $party): bool
    {
        return $user->tenant_id === $party->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function update(User $user, Party $party): bool
    {
        return $user->tenant_id === $party->tenant_id && in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    public function delete(User $user, Party $party): bool
    {
        return $user->tenant_id === $party->tenant_id && $user->role?->name === 'admin';
    }
}
