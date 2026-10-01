<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * §11: "Sales — Lead, Quotation, Feasibility Check." Delivery/SalesReturn
 * aren't explicitly named under any role - Warehouse is added alongside
 * Sales/Admin for those (a physical stock event, same reasoning
 * procurement's SupplierReturnPolicy already applied). BOQ/BOQSection/
 * BOQLine/MeasurementSheet/Markup/BOQImportStaging aren't named under any
 * role either and span more than Sales (boqable is Project or
 * Subcontract) - defaulted broadly to Admin/Sales/Procurement/
 * Project Manager, flagged the same way.
 */
trait ChecksSalesRoles
{
    private const VIEW_ROLES = ['admin', 'sales', 'warehouse'];

    private const MANAGE_ROLES = ['admin', 'sales'];

    protected function canView(User $user): bool
    {
        return in_array($user->role?->name, self::VIEW_ROLES, true);
    }

    protected function canManage(User $user): bool
    {
        return in_array($user->role?->name, self::MANAGE_ROLES, true);
    }

    protected function isAdmin(User $user): bool
    {
        return $user->role?->name === 'admin';
    }
}
