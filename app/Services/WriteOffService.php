<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WriteOff;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * §3.9/§7: "Dr Bad Debt Expense, Cr Accounts Receivable." Only Invoice
 * write-offs are implemented - LedgerPostingService::postWriteOff()
 * hardcodes reference_type='Invoice' and there is no distinct posting
 * shape for a ProgressClaim write-off anywhere in ledger-core's ported
 * set (a subcontractor claim is Imara's liability, not a receivable
 * Imara could fail to collect, so the same Bad-Debt-Expense/AR posting
 * wouldn't even be correct for it). write_offs.progress_claim_id exists
 * for the field the architecture names, but this service only ever
 * posts the Invoice path - flagged, not silently assumed.
 */
class WriteOffService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function writeOffInvoice(Invoice $invoice, string $amountMajor, string $reason, User $approvedBy): WriteOff
    {
        return DB::transaction(function () use ($invoice, $amountMajor, $reason, $approvedBy) {
            $amount = \App\Support\Money::fromMajor($amountMajor);

            $writeOff = WriteOff::create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount_cents' => $amount,
                'reason' => $reason,
                'approved_by' => $approvedBy->id,
            ]);

            $entryInput = $this->ledger->postWriteOff((string) $invoice->id, $amount);
            $entry = $this->ledger->commit($invoice->tenant, $entryInput, BusinessTime::today(), $approvedBy, $writeOff->id);

            $writeOff->update(['journal_entry_id' => $entry->id]);
            $invoice->update(['status' => 'written_off']);

            return $writeOff->fresh();
        });
    }
}
