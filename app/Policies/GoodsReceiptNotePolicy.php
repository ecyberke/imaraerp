<?php

namespace App\Policies;

use App\Models\GoodsReceiptNote;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class GoodsReceiptNotePolicy
{
    use ChecksProcurementRoles;

    /**
     * GRN-specific, not ChecksProcurementRoles' shared canView() - that
     * trait's VIEW_ROLES is reused across PO/LandedCost/SupplierPayment
     * too, and site_supervisor having GRN-creation rights (below)
     * shouldn't also grant visibility into every other procurement
     * entity by way of a shared trait constant. Named differently from
     * the trait's own VIEW_ROLES - PHP treats a same-named constant
     * defined in both a trait and the class using it as a fatal
     * incompatible-composition error, not a silent override.
     */
    private const GRN_VIEW_ROLES = ['admin', 'procurement', 'warehouse', 'site_supervisor'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::GRN_VIEW_ROLES, true);
    }

    public function view(User $user, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $user->tenant_id === $goodsReceiptNote->tenant_id && in_array($user->role?->name, self::GRN_VIEW_ROLES, true);
    }

    /**
     * §11 (resolved, approval-notification-compliance): "Site Supervisor
     * — Project Utilization, Quality Sign Off, and GRN creation for
     * goods received directly at site" is §11's own stated default, not
     * an open question - site staff, not the warehouse, are routinely
     * the ones physically present when a delivery arrives at a project
     * site. No real client engagement exists yet to confirm this against
     * an actual site-to-office workflow (the warehouse-handshake
     * alternative §11 names stays available as a per-client
     * configuration choice, not a code change, if a client's real
     * process turns out to be informal-site-log-then-office-GRN
     * instead), so this follows the architecture doc's own default
     * rather than leaving the gap open.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'procurement', 'warehouse', 'site_supervisor'], true);
    }
}
