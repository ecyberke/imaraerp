<?php

namespace App\Policies;

use App\Models\GoodsReceiptNote;
use App\Models\User;
use App\Policies\Concerns\ChecksProcurementRoles;

class GoodsReceiptNotePolicy
{
    use ChecksProcurementRoles;

    public function viewAny(User $user): bool
    {
        return $this->canView($user);
    }

    public function view(User $user, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $user->tenant_id === $goodsReceiptNote->tenant_id && $this->canView($user);
    }

    /** §9/§11: Site Supervisor is a named GRN-creation candidate too
     * (architecture §9/§11 open item) - not added here yet, since the
     * kickoff brief flags confirming this against the first real client's
     * actual site-to-office workflow before building the permission
     * model around it (a warehouse-handshake alternative is equally
     * valid). Admin/Procurement/Warehouse can create for now.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'procurement', 'warehouse'], true);
    }
}
