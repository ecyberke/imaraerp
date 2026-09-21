<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;

/**
 * §11: "Finance — Chart of Accounts, Journal Entries, Credit Approval,
 * Payments" - Payments (even this procurement-scoped disbursement stand-in,
 * see the supplier_payments migration) is explicitly Finance's, not
 * Procurement's, unlike the other entities in this branch.
 */
class SupplierPaymentPolicy
{
    private const VIEW_ROLES = ['admin', 'finance', 'procurement'];

    private const MANAGE_ROLES = ['admin', 'finance'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function view(User $user, SupplierPayment $supplierPayment): bool
    {
        return $user->tenant_id === $supplierPayment->tenant_id && in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
