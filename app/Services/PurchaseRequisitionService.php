<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\Money;

/**
 * §3.10/ApprovalLimit: PurchaseRequisition carries no monetary value
 * anywhere in this schema (see the approval_limits migration's own
 * docblock) - evaluated against Money::zero(), so this only ever
 * enforces the role gate (does this role have an ApprovalLimit row for
 * purchase_requisition at all), never a real threshold or second
 * approval.
 */
class PurchaseRequisitionService
{
    public function __construct(private ApprovalLimitService $approvalLimits) {}

    public function approve(PurchaseRequisition $requisition, User $approver): PurchaseRequisition
    {
        if ($requisition->status !== 'draft') {
            throw new \DomainException("Cannot approve a PurchaseRequisition with status '{$requisition->status}'.");
        }

        $decision = $this->approvalLimits->evaluate($approver->tenant, 'purchase_requisition', $approver, Money::zero());
        if (! $decision['approved']) {
            throw new \DomainException($decision['reason']);
        }

        $requisition->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);

        return $requisition->fresh();
    }

    public function reject(PurchaseRequisition $requisition): PurchaseRequisition
    {
        if ($requisition->status !== 'draft') {
            throw new \DomainException("Cannot reject a PurchaseRequisition with status '{$requisition->status}'.");
        }

        $requisition->update(['status' => 'rejected']);

        return $requisition->fresh();
    }
}
