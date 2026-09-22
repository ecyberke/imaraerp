<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §7/finance-billing exit criterion: "a Sales Return posts correctly...
 * matching ledger-core's verified postings" (postSalesReturn). Distinct
 * from crm-sales-boq's SalesReturnService, which only ever handled the
 * physical side (a real restocking StockLedger row) - SalesReturn (that
 * branch's table) carries no invoice_id, and a SalesOrder can be billed
 * across several Invoices depending on invoice_policy (on_order/
 * on_delivery/on_milestone), so which Invoice a given return's revenue
 * reversal applies against - and what proportion of it - isn't something
 * this service can safely infer from the SalesReturn row alone without
 * adding invoice-tracking fields to Delivery/SalesReturn, which is
 * crm-sales-boq's schema, not this branch's. Flagged rather than
 * silently auto-wired: this service posts the correct §7 entry once the
 * caller (a controller, or a later UI-side reconciliation step) supplies
 * which Invoice and what proportion/COGS the return represents.
 */
class SalesReturnFinancialsService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function postAgainstInvoice(
        Invoice $invoice,
        string $proportion,
        Money $cogsValueOfReturnedGoods,
        string $returnReference,
    ): \App\Models\JournalEntry {
        return DB::transaction(function () use ($invoice, $proportion, $cogsValueOfReturnedGoods, $returnReference) {
            $invoice->loadMissing('taxCode');
            $vatRate = (string) $invoice->taxCode->rate;
            $vatOnRetention = $invoice->retention_amount->multiplyByRate($vatRate);
            $retentionPlusVat = $invoice->retention_amount->add($vatOnRetention);

            $entryInput = $this->ledger->postSalesReturn(
                $returnReference,
                $invoice->gross_amount,
                $invoice->vat_amount,
                $invoice->net_payable,
                $retentionPlusVat,
                $proportion,
                $cogsValueOfReturnedGoods,
            );

            return $this->ledger->commit($invoice->tenant, $entryInput, BusinessTime::today(), null, $invoice->id);
        });
    }
}
