<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\RetentionAccount;
use App\Models\SalesOrder;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: Invoice/InvoiceLine creation and raising. gross_amount/vat_amount
 * are authoritative from the per-line sum (InvoiceLine's own rounding
 * rule - "per-line-summed is authoritative, never backed into from an
 * invoice-level total"), passed through to LedgerPostingService's
 * postInvoiceRaised() via its precomputed-amount overrides rather than
 * letting that method recompute a possibly-different figure from a flat
 * rate.
 */
class InvoiceService
{
    public function __construct(
        private DocumentSequenceService $sequences,
        private RetentionCalculationService $retention,
        private LedgerPostingService $ledger,
    ) {}

    /**
     * @param  array<int, array{description: string, quantity: string, unit_price: string, source_type?: string, source_id?: int}>  $lines
     */
    public function create(
        Tenant $tenant,
        SalesOrder $salesOrder,
        array $lines,
        string $paymentTerms,
        string $taxCodeCode = 'VAT_STANDARD',
        ?string $retentionPercentage = null,
        ?\Carbon\CarbonInterface $invoiceDate = null,
        ?\Carbon\CarbonInterface $postingDate = null,
    ): Invoice {
        return DB::transaction(function () use (
            $tenant, $salesOrder, $lines, $paymentTerms, $taxCodeCode, $retentionPercentage, $invoiceDate, $postingDate,
        ) {
            $taxCode = TaxCode::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('code', $taxCodeCode)->firstOrFail();
            $vatRate = (string) $taxCode->rate;

            $gross = Money::zero();
            $vatSum = Money::zero();
            $lineModels = [];
            foreach ($lines as $line) {
                $unitPrice = Money::fromMajor($line['unit_price']);
                $lineTotal = $unitPrice->multiply($line['quantity']);
                $lineVat = $lineTotal->multiplyByRate($vatRate);

                $gross = $gross->add($lineTotal);
                $vatSum = $vatSum->add($lineVat);

                $lineModels[] = [
                    'tenant_id' => $tenant->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $unitPrice,
                    'line_total_cents' => $lineTotal,
                    'vat_amount_cents' => $lineVat,
                    'source_type' => $line['source_type'] ?? null,
                    'source_id' => $line['source_id'] ?? null,
                ];
            }

            $terms = $this->retention->findTerms(SalesOrder::class, $salesOrder->id);
            $retentionPercentage ??= $terms?->retention_percentage ?? '0';
            $naiveRetention = $gross->multiplyByRate($retentionPercentage);
            $retentionAmount = $this->retention->applyCap($terms, $naiveRetention);
            $vatOnRetention = $retentionAmount->multiplyByRate($vatRate);

            $netPayable = $gross->add($vatSum)->sub($retentionAmount)->sub($vatOnRetention);

            $invoice = Invoice::create([
                'tenant_id' => $tenant->id,
                'document_number' => $this->sequences->next($tenant, 'invoice'),
                'sales_order_id' => $salesOrder->id,
                'party_id' => $salesOrder->party_id,
                'invoice_date' => $invoiceDate ?? \App\Support\BusinessTime::today(),
                'posting_date' => $postingDate ?? \App\Support\BusinessTime::today(),
                'payment_terms' => $paymentTerms,
                'credit_approval_status' => $paymentTerms === 'cash' ? 'not_required' : 'pending',
                'gross_amount_cents' => $gross,
                'vat_amount_cents' => $vatSum,
                'retention_percentage' => $retentionPercentage,
                'retention_amount_cents' => $retentionAmount,
                'net_payable_cents' => $netPayable,
                'tax_code_id' => $taxCode->id,
                'status' => 'draft',
            ]);

            foreach ($lineModels as $lineModel) {
                $invoice->lines()->create($lineModel);
            }

            return $invoice->fresh('lines');
        });
    }

    /**
     * @param  array<int, int>  $advancePaymentAllocationIds  unallocated PaymentAllocation rows (invoice_id=null) to apply against this invoice
     */
    public function raise(Invoice $invoice, array $advancePaymentAllocationIds = [], ?User $raisedBy = null): Invoice
    {
        if ($invoice->payment_terms === 'credit' && ! in_array($invoice->credit_approval_status, ['approved', 'overridden'], true)) {
            throw new \DomainException("Cannot raise a credit invoice with credit_approval_status '{$invoice->credit_approval_status}'.");
        }

        return DB::transaction(function () use ($invoice, $advancePaymentAllocationIds, $raisedBy) {
            $invoice->loadMissing('taxCode', 'salesOrder');
            $tenant = $invoice->tenant;

            $advances = PaymentAllocation::withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->whereIn('id', $advancePaymentAllocationIds)
                ->whereNull('invoice_id')
                ->get();
            $advanceRecovery = Money::sum(...$advances->map(fn (PaymentAllocation $a) => $a->amount_allocated)->all());

            if ($advanceRecovery->greaterThan($invoice->net_payable)) {
                throw new \DomainException(sprintf(
                    'Advance recovery (%s) cannot exceed this invoice\'s net_payable (%s) - apply only part of the advance instead.',
                    $advanceRecovery, $invoice->net_payable,
                ));
            }

            $vatRate = (string) $invoice->taxCode->rate;

            $entryInput = $this->ledger->postInvoiceRaised(
                (string) $invoice->id,
                $invoice->gross_amount,
                $vatRate,
                (string) $invoice->retention_percentage,
                $advanceRecovery->isPositive() ? $advanceRecovery : null,
                null,
                $invoice->vat_amount,
                $invoice->retention_amount,
            );
            $entry = $this->ledger->commit($tenant, $entryInput, $invoice->posting_date, $raisedBy, $invoice->id);

            $invoice->update(['status' => 'raised', 'journal_entry_id' => $entry->id]);

            foreach ($advances as $advance) {
                $advance->update(['invoice_id' => $invoice->id]);
            }

            if ($invoice->retention_amount->isPositive()) {
                $vatOnRetention = $invoice->retention_amount->multiplyByRate($vatRate);

                RetentionAccount::create([
                    'tenant_id' => $invoice->tenant_id,
                    'contract_type' => SalesOrder::class,
                    'contract_id' => $invoice->sales_order_id,
                    'party_id' => $invoice->party_id,
                    'direction' => 'receivable',
                    'invoice_id' => $invoice->id,
                    'amount_cents' => $invoice->retention_amount->add($vatOnRetention),
                    'released_amount_cents' => Money::zero(),
                ]);
            }

            return $invoice->fresh();
        });
    }
}
