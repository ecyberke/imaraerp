<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: Payment (receipt direction) + PaymentAllocation. A payment can
 * settle several invoices, include a client-withheld-WHT shortfall, and
 * carry an unallocated excess (invoice_id=null = Customer Advance, §3.9)
 * all in one receipt - two separate JournalEntries are posted when both
 * an invoice-settling portion and an advance portion are present
 * (postPaymentReceived only ever credits Accounts Receivable; the
 * advance portion needs postCustomerAdvanceReceived's distinct Customer
 * Advance credit - see that method's docblock in LedgerPostingService).
 */
class PaymentService
{
    public function __construct(private LedgerPostingService $ledger) {}

    /**
     * @param  array<int, array{invoice_id: int, amount: string}>  $invoiceAllocations
     */
    public function receive(
        Tenant $tenant,
        Party $party,
        array $invoiceAllocations,
        string $advanceAmount = '0',
        ?string $whtWithheldByClient = null,
        ?string $whtTaxCodeCode = null,
        string $method = 'bank_transfer',
        ?string $mpesaReference = null,
        ?User $receivedBy = null,
        ?BankAccount $bankAccount = null,
    ): Payment {
        if ($invoiceAllocations === [] && bccomp($advanceAmount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('receive() requires at least one invoice allocation or a positive advance amount.');
        }

        return DB::transaction(function () use (
            $tenant, $party, $invoiceAllocations, $advanceAmount, $whtWithheldByClient, $whtTaxCodeCode, $method, $mpesaReference, $receivedBy, $bankAccount,
        ) {
            $wht = $whtWithheldByClient ? Money::fromMajor($whtWithheldByClient) : Money::zero();
            $advance = Money::fromMajor($advanceAmount);

            $invoices = [];
            $netPayableAllocated = Money::zero();
            foreach ($invoiceAllocations as $allocation) {
                $invoice = Invoice::where('tenant_id', $tenant->id)->findOrFail($allocation['invoice_id']);
                $amount = Money::fromMajor($allocation['amount']);
                $invoices[] = ['invoice' => $invoice, 'amount' => $amount];
                $netPayableAllocated = $netPayableAllocated->add($amount);
            }

            $cashReceived = $netPayableAllocated->sub($wht)->add($advance);

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'party_id' => $party->id,
                'direction' => 'receipt',
                'amount_cents' => $cashReceived,
                'method' => $method,
                'mpesa_reference' => $mpesaReference,
                'tax_code_id' => $wht->isPositive() && $whtTaxCodeCode
                    ? \App\Models\TaxCode::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', $whtTaxCodeCode)->value('id')
                    : null,
                'wht_amount_cents' => $wht->isPositive() ? $wht : null,
                'bank_account_id' => $bankAccount?->id,
                'received_at' => now(),
                'posting_date' => BusinessTime::today(),
            ]);

            if ($netPayableAllocated->isPositive()) {
                $entryInput = $this->ledger->postPaymentReceived(
                    (string) $payment->id,
                    array_map(fn ($a) => ['reference' => (string) $a['invoice']->id, 'amount' => $a['amount']], $invoices),
                    $wht->isPositive() ? $wht : null,
                );
                $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), $receivedBy, $payment->id);
            }

            if ($advance->isPositive()) {
                $advanceEntryInput = $this->ledger->postCustomerAdvanceReceived((string) $payment->id, $advance);
                $this->ledger->commit($tenant, $advanceEntryInput, BusinessTime::today(), $receivedBy, $payment->id);
            }

            foreach ($invoices as $a) {
                PaymentAllocation::create([
                    'tenant_id' => $tenant->id,
                    'payment_id' => $payment->id,
                    'invoice_id' => $a['invoice']->id,
                    'amount_allocated_cents' => $a['amount'],
                    'status' => 'allocated',
                ]);

                $this->refreshInvoiceSettlementStatus($a['invoice']);
            }

            if ($advance->isPositive()) {
                PaymentAllocation::create([
                    'tenant_id' => $tenant->id,
                    'payment_id' => $payment->id,
                    'invoice_id' => null,
                    'amount_allocated_cents' => $advance,
                    'status' => 'allocated',
                ]);
            }

            return $payment->fresh('allocations');
        });
    }

    /**
     * §3.9: "an invoice is 'settled' once cash-allocated plus
     * WHT-credited equals net_payable, not once amount_allocated alone
     * does." WHT credit against this invoice is inferred from Payments
     * carrying wht_amount_cents whose allocations touch this invoice -
     * tracked via the allocation's own Payment relation rather than a
     * separate stored figure, since a Payment's WHT applies to the whole
     * receipt, not a specific allocation line.
     */
    private function refreshInvoiceSettlementStatus(Invoice $invoice): void
    {
        $invoice->refresh();

        $allocations = PaymentAllocation::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', 'allocated')
            ->with('payment')
            ->get();

        $cashAllocated = Money::sum(...$allocations->map(fn (PaymentAllocation $a) => $a->amount_allocated)->all());
        $whtCredited = Money::sum(...$allocations->map(fn (PaymentAllocation $a) => $a->payment->wht_amount ?? Money::zero())->all());
        $settled = $cashAllocated->add($whtCredited);

        if (! $settled->lessThan($invoice->net_payable)) {
            $invoice->update(['status' => 'paid']);
        } elseif ($settled->isPositive()) {
            $invoice->update(['status' => 'partially_paid']);
        }
    }

    public function refundAdvance(PaymentAllocation $advance, ?User $refundedBy = null): PaymentAllocation
    {
        if (! $advance->isAdvance() || $advance->status !== 'allocated') {
            throw new \DomainException('Only a live, unallocated (invoice_id = null) PaymentAllocation can be refunded.');
        }

        return DB::transaction(function () use ($advance, $refundedBy) {
            $tenant = $advance->tenant;

            $entryInput = $this->ledger->postCustomerAdvanceRefunded((string) $advance->id, $advance->amount_allocated);
            $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), $refundedBy, $advance->id);

            $advance->update(['status' => 'refunded']);

            return $advance->fresh();
        });
    }
}
