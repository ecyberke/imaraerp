<?php

namespace App\Policies;

use App\Models\User;

/**
 * Non-sensitive reference data (currency codes) needed by nearly every
 * module (Sales quotations, Procurement POs, Finance payments) - open
 * to any authenticated user of the tenant, same reasoning TaxCode/
 * ChartOfAccount would get if they had their own read endpoints.
 */
class CurrencyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
