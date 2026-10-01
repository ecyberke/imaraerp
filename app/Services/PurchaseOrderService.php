<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;

/**
 * §5.2/procurement's own flagged gap, resolved here: requisitioned ->
 * pending_approval -> approved -> ordered, real ApprovalLimit-gated
 * approval against the PO's own total value (sum of quantity_ordered x
 * unit_cost across its lines). approved -> ordered stays a separate
 * manual confirmation ("the order was actually placed with the
 * supplier") distinct from commercial approval - same approved/executed
 * split VariationOrder already uses.
 */
class PurchaseOrderService
{
    public function __construct(private ApprovalLimitService $approvalLimits, private NotificationService $notifications) {}

    public function create(Tenant $tenant, array $data): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'tenant_id' => $tenant->id,
            'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
            'party_id' => $data['party_id'],
            'currency_id' => $data['currency_id'],
            'exchange_rate' => $data['exchange_rate'] ?? 1,
            'status' => 'requisitioned',
        ]);

        foreach ($data['lines'] as $line) {
            $po->lines()->create([
                'tenant_id' => $tenant->id,
                'purchase_requisition_line_id' => $line['purchase_requisition_line_id'] ?? null,
                'item_id' => $line['item_id'],
                'quantity_ordered' => $line['quantity_ordered'],
                'unit_cost_cents' => Money::fromMajor($line['unit_cost']),
            ]);
        }

        return $po->fresh('lines');
    }

    public function submitForApproval(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'requisitioned') {
            throw new \DomainException("Cannot submit a PurchaseOrder with status '{$po->status}' for approval.");
        }

        $po->update(['status' => 'pending_approval']);

        return $po->fresh();
    }

    public function approve(PurchaseOrder $po, User $approver, ?User $secondApprover = null): PurchaseOrder
    {
        if ($po->status !== 'pending_approval') {
            throw new \DomainException("Cannot approve a PurchaseOrder with status '{$po->status}'.");
        }

        $decision = $this->approvalLimits->evaluate($po->tenant, 'purchase_order', $approver, $this->totalValue($po));
        if (! $decision['approved']) {
            throw new \DomainException($decision['reason']);
        }

        if ($decision['requires_second_approval']) {
            if (! $secondApprover) {
                $this->notifications->notifyApprovalPendingForRole($po->tenant, $decision['second_approver_role_id'], PurchaseOrder::class, $po->id, "PurchaseOrder #{$po->id} needs a second approval.");

                throw new \DomainException('This PurchaseOrder requires a second approval above the configured threshold.');
            }
            $this->approvalLimits->assertSecondApprover($approver, $secondApprover, $decision['second_approver_role_id']);
        }

        $po->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'second_approved_by' => $secondApprover?->id,
            'approved_at' => now(),
        ]);

        return $po->fresh();
    }

    public function reject(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'pending_approval') {
            throw new \DomainException("Cannot reject a PurchaseOrder with status '{$po->status}'.");
        }

        $po->update(['status' => 'requisitioned']);

        return $po->fresh();
    }

    public function markOrdered(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'approved') {
            throw new \DomainException("Cannot mark a PurchaseOrder with status '{$po->status}' as ordered.");
        }

        $po->update(['status' => 'ordered']);

        return $po->fresh();
    }

    private function totalValue(PurchaseOrder $po): Money
    {
        $po->loadMissing('lines');

        return Money::sum(...$po->lines->map(fn (PurchaseOrderLine $l) => $l->unit_cost->multiply((string) $l->quantity_ordered))->all());
    }
}
