<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.10 defines ApprovalLimit as data, not hardcoded logic - but a fresh
 * tenant with zero rows configured would be unable to approve a single
 * PR/PO/VariationOrder/CreditApproval override until an admin manually
 * populates it first, which defeats the point of shipping the approval
 * mechanism at all. Seeds one sensible starting row per entity_type for
 * the role §11 names as that entity's owner, editable afterward via the
 * ApprovalLimit UI this branch also ships - the same "seed reasonable
 * defaults, not an empty table" precedent as StatutoryDeductionRateSeeder
 * and ChartOfAccountsSeeder.
 */
final class ApprovalLimitSeeder
{
    public static function seed(Tenant $tenant): void
    {
        $now = now();
        $roleId = fn (string $name) => Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', $name)->value('id');

        $procurement = $roleId('procurement');
        $finance = $roleId('finance');
        $projectManager = $roleId('project_manager');
        $admin = $roleId('admin');

        $rows = [
            // No real amount exists on a PurchaseRequisition (see the
            // approval_limits migration's docblock) - max_amount here is
            // never actually compared against anything but zero.
            ['role_id' => $procurement, 'entity_type' => 'purchase_requisition', 'max_amount_cents' => Money::fromMajor('100000000')->cents(), 'requires_second_approval_above_cents' => null, 'second_approver_role_id' => null],
            ['role_id' => $procurement, 'entity_type' => 'purchase_order', 'max_amount_cents' => Money::fromMajor('500000')->cents(), 'requires_second_approval_above_cents' => Money::fromMajor('200000')->cents(), 'second_approver_role_id' => $finance],
            ['role_id' => $finance, 'entity_type' => 'credit_approval', 'max_amount_cents' => Money::fromMajor('1000000')->cents(), 'requires_second_approval_above_cents' => Money::fromMajor('500000')->cents(), 'second_approver_role_id' => $admin],
            ['role_id' => $projectManager, 'entity_type' => 'variation_order', 'max_amount_cents' => Money::fromMajor('1000000')->cents(), 'requires_second_approval_above_cents' => Money::fromMajor('300000')->cents(), 'second_approver_role_id' => $admin],
        ];

        DB::table('approval_limits')->insert(array_map(fn ($row) => [
            ...$row,
            'tenant_id' => $tenant->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows));
    }
}
