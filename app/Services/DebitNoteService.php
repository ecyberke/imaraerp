<?php

namespace App\Services;

use App\Models\DebitNote;
use App\Models\Invoice;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/** Mirrors CreditNoteService exactly - see its docblock/§3.9. */
class DebitNoteService
{
    public function __construct(
        private DocumentSequenceService $sequences,
        private LedgerPostingService $ledger,
    ) {}

    public function issue(Invoice $invoice, string $proportion, string $reason, ?\Carbon\CarbonInterface $noteDate = null): DebitNote
    {
        if ($invoice->status !== 'raised' && $invoice->status !== 'partially_paid') {
            throw new \DomainException("Cannot issue a Debit Note against an Invoice with status '{$invoice->status}'.");
        }

        return DB::transaction(function () use ($invoice, $proportion, $reason, $noteDate) {
            $invoice->loadMissing('taxCode', 'lines');
            $tenant = $invoice->tenant;
            $vatRate = (string) $invoice->taxCode->rate;
            $vatOnRetention = $invoice->retention_amount->multiplyByRate($vatRate);
            $retentionPlusVat = $invoice->retention_amount->add($vatOnRetention);

            $debitNote = DebitNote::create([
                'tenant_id' => $invoice->tenant_id,
                'document_number' => $this->sequences->next($tenant, 'debit_note'),
                'invoice_id' => $invoice->id,
                'amount_cents' => $invoice->gross_amount->multiplyByRate($proportion),
                'reason' => $reason,
                'note_date' => $noteDate ?? BusinessTime::today(),
                'posting_date' => BusinessTime::today(),
                'status' => 'draft',
            ]);

            foreach ($invoice->lines as $line) {
                $debitNote->lines()->create([
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

            $entryInput = $this->ledger->postDebitNoteIssued(
                (string) $debitNote->id,
                $invoice->gross_amount,
                $invoice->vat_amount,
                $invoice->net_payable,
                $retentionPlusVat,
                $proportion,
            );
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), null, $debitNote->id);

            $debitNote->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $debitNote->fresh('lines');
        });
    }
}
