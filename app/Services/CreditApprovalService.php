<?php

namespace App\Services;

use App\Models\CreditApproval;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: "at Credit Approval, the check is (sum of net_payable across that
 * Party's open Invoices) + this invoice's net_payable ≤ Party.credit_limit.
 * Default behavior is block, not warn." "open Invoices" here means
 * already-approved/overridden ones still outstanding (not yet paid or
 * written off) - a 'pending' invoice hasn't been confirmed to consume
 * credit yet, and it's exactly the Party row lock (held for the whole
 * compute-then-decide-then-persist sequence) that makes two concurrent
 * requests for the same client serialize correctly: the second's SELECT
 * only runs after the first COMMITs, by which point the first's decision
 * is already 'approved' and counted.
 */
class CreditApprovalService
{
    public function request(Invoice $invoice, ?User $requestedBy = null): CreditApproval
    {
        return DB::transaction(function () use ($invoice, $requestedBy) {
            $party = Party::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('id', $invoice->party_id)
                ->lockForUpdate()
                ->firstOrFail();

            $openInvoices = Invoice::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('party_id', $party->id)
                ->where('id', '!=', $invoice->id)
                ->whereIn('credit_approval_status', ['approved', 'overridden'])
                ->whereNotIn('status', ['paid', 'written_off'])
                ->get();

            $exposure = Money::sum(...$openInvoices->map(fn (Invoice $i) => $i->net_payable)->all());
            $projected = $exposure->add($invoice->net_payable);

            $creditLimit = $party->credit_limit ?? Money::zero();
            $withinLimit = ! $projected->greaterThan($creditLimit);

            $approval = CreditApproval::create([
                'tenant_id' => $invoice->tenant_id,
                'sales_order_id' => $invoice->sales_order_id,
                'invoice_id' => $invoice->id,
                'requested_amount_cents' => $invoice->net_payable,
                'approved_by' => $withinLimit ? $requestedBy?->id : null,
                'approved_at' => $withinLimit ? now() : null,
                'status' => $withinLimit ? 'approved' : 'rejected',
            ]);

            $invoice->update(['credit_approval_status' => $withinLimit ? 'approved' : 'rejected']);

            return $approval;
        });
    }

    /**
     * §3.9: "a Finance user can override via an explicit action, which
     * CreditApproval.status = overridden records with the same audit
     * weight as any other approval decision."
     */
    public function override(CreditApproval $approval, User $approver, string $notes): CreditApproval
    {
        if ($approval->status !== 'rejected') {
            throw new \DomainException("Cannot override a credit approval with status '{$approval->status}'.");
        }

        return DB::transaction(function () use ($approval, $approver, $notes) {
            $approval->update([
                'status' => 'overridden',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'notes' => $notes,
            ]);

            $approval->invoice?->update(['credit_approval_status' => 'overridden']);

            return $approval->fresh();
        });
    }
}
