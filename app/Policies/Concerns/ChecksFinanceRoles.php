<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * §11: "Finance — Chart of Accounts, Journal Entries, Credit Approval,
 * Payments." Invoice/InvoiceLine, CreditNote/DebitNote, WriteOff,
 * RetentionAccount/RetentionRelease/ContractRetentionTerms,
 * CapitalMovement, and OpeningBalanceBatch aren't individually named,
 * but every one of §11's *named* Finance entities is exactly this kind
 * of billing/ledger-adjacent record - defaulted to the same Admin/
 * Finance pair CreditApproval and Payment explicitly get, flagged the
 * same way every other ungoverned entity in this codebase has been.
 */
trait ChecksFinanceRoles
{
    private const VIEW_ROLES = ['admin', 'finance'];

    private const MANAGE_ROLES = ['admin', 'finance'];

    protected function canView(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    protected function canManage(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }
}
