<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: "a KRA-numbered invoice can't be corrected by raising a negative
 * invoice ... or a bare journal entry ... every correction has to flow
 * through one of these [CreditNote/DebitNote]." Figures are pulled from
 * the original Invoice's own stored, already-authoritative amounts
 * (never re-derived from the posted JournalEntry) - matching
 * postCreditNoteIssued's own assertOriginalInvoiceFiguresAreConsistent()
 * guard.
 */
class CreditNoteService
{
    public function __construct(
        private DocumentSequenceService $sequences,
        private LedgerPostingService $ledger,
    ) {}

    public function issue(Invoice $invoice, string $proportion, string $reason, ?\Carbon\CarbonInterface $noteDate = null): CreditNote
    {
        if ($invoice->status !== 'raised' && $invoice->status !== 'partially_paid') {
            throw new \DomainException("Cannot issue a Credit Note against an Invoice with status '{$invoice->status}'.");
        }

        return DB::transaction(function () use ($invoice, $proportion, $reason, $noteDate) {
            $invoice->loadMissing('taxCode', 'lines');
            $tenant = $invoice->tenant;
            $vatRate = (string) $invoice->taxCode->rate;
            $vatOnRetention = $invoice->retention_amount->multiplyByRate($vatRate);
            $retentionPlusVat = $invoice->retention_amount->add($vatOnRetention);

            $creditNote = CreditNote::create([
                'tenant_id' => $invoice->tenant_id,
                'document_number' => $this->sequences->next($tenant, 'credit_note'),
                'invoice_id' => $invoice->id,
                'amount_cents' => $invoice->gross_amount->multiplyByRate($proportion),
                'reason' => $reason,
                'note_date' => $noteDate ?? BusinessTime::today(),
                'posting_date' => BusinessTime::today(),
                'status' => 'draft',
            ]);

            foreach ($invoice->lines as $line) {
                $creditNote->lines()->create([
                    'tenant_id' => $invoice->tenant_id,
                    'description' => $line->description,
                    'quantity' => bcmul((string) $line->quantity, $proportion, 4),
                    'unit_price_cents' => $line->unit_price,
                    'line_total_cents' => $line->line_total->multiplyByRate($proportion),
                    'vat_amount_cents' => $line->vat_amount->multiplyByRate($proportion),
                    'source_type' => $line->source_type,
                    'source_id' => $line->source_id,
                ]);
            }

            $entryInput = $this->ledger->postCreditNoteIssued(
                (string) $creditNote->id,
                $invoice->gross_amount,
                $invoice->vat_amount,
                $invoice->net_payable,
                $retentionPlusVat,
                $proportion,
            );
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), null, $creditNote->id);

            $creditNote->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            $retentionAccount = $invoice->retentionAccount;
            if ($retentionAccount) {
                $reduction = $retentionPlusVat->multiplyByRate($proportion);
                $newAmount = $retentionAccount->amount->sub($reduction);
                $retentionAccount->update([
                    'amount_cents' => $newAmount->isNegative() ? Money::zero() : $newAmount,
                ]);
            }

            return $creditNote->fresh('lines');
        });
    }
}
