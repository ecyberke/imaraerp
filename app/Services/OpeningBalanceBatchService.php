<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\OpeningBalanceBatch;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProgressClaim;
use App\Models\RetentionAccount;
use App\Models\StockLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * §8/§3.9: the actual migration mechanism. Historical rows are inserted
 * directly (is_opening_balance = true), never through the normal
 * create-and-post services (InvoiceService::raise() etc. would try to
 * post individual JournalEntries per row and apply live validation that
 * doesn't apply to a bulk historical load) - the whole batch is tied
 * together by exactly ONE opening JournalEntry, posted once via post().
 *
 * NOT covered here, flagged rather than silently dropped: Asset and
 * AssetDepreciationEntry backfill (asset-management branch, not yet
 * built - there is no Asset model to insert historical rows against).
 * The real implementation adds addOpeningAsset()/addOpeningDepreciation()
 * once that branch lands; this service handles every entity that exists
 * today (Invoice, Payment, PaymentAllocation, RetentionAccount,
 * ProgressClaim, StockLedger).
 */
class OpeningBalanceBatchService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function create(Tenant $tenant, \Carbon\CarbonInterface $asOfDate): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::create([
            'tenant_id' => $tenant->id,
            'as_of_date' => $asOfDate,
            'status' => 'draft',
        ]);
    }

    /** @param  array<string, mixed>  $attributes  Invoice::create() attributes, minus tenant_id/is_opening_balance */
    public function addOpeningInvoice(OpeningBalanceBatch $batch, array $attributes): Invoice
    {
        $this->assertDraft($batch);

        return Invoice::create([...$attributes, 'tenant_id' => $batch->tenant_id, 'is_opening_balance' => true]);
    }

    public function addOpeningPayment(OpeningBalanceBatch $batch, array $attributes): Payment
    {
        $this->assertDraft($batch);

        return Payment::create([...$attributes, 'tenant_id' => $batch->tenant_id, 'is_opening_balance' => true]);
    }

    public function addOpeningPaymentAllocation(OpeningBalanceBatch $batch, array $attributes): PaymentAllocation
    {
        $this->assertDraft($batch);

        return PaymentAllocation::create([...$attributes, 'tenant_id' => $batch->tenant_id, 'is_opening_balance' => true]);
    }

    public function addOpeningRetentionAccount(OpeningBalanceBatch $batch, array $attributes): RetentionAccount
    {
        $this->assertDraft($batch);

        return RetentionAccount::create([...$attributes, 'tenant_id' => $batch->tenant_id, 'is_opening_balance' => true]);
    }

    public function addOpeningProgressClaim(OpeningBalanceBatch $batch, array $attributes): ProgressClaim
    {
        $this->assertDraft($batch);

        return ProgressClaim::create([...$attributes, 'tenant_id' => $batch->tenant_id, 'is_opening_balance' => true]);
    }

    /**
     * §8: "Stock opening is a single valuation layer at one blended unit
     * cost per item as of as_of_date - no historical FIFO layers are
     * reconstructed."
     */
    public function addOpeningStock(OpeningBalanceBatch $batch, array $attributes): StockLedger
    {
        $this->assertDraft($batch);

        return StockLedger::create([
            ...$attributes,
            'tenant_id' => $batch->tenant_id,
            'movement_type' => 'adjustment',
            'reference_type' => OpeningBalanceBatch::class,
            'reference_id' => $batch->id,
            'is_opening_balance' => true,
        ]);
    }

    /**
     * §3.10: sets the tenant's DocumentSequence for one document type to
     * one-past a migrating client's last KRA-issued number, so the next
     * document raised in the live system continues that numbering
     * instead of restarting at 1.
     */
    public function initializeDocumentSequence(Tenant $tenant, string $entityType, int $lastIssuedNumber, ?int $fiscalYear = null): DocumentSequence
    {
        if (! array_key_exists($entityType, DocumentSequenceService::PREFIXES)) {
            throw new \InvalidArgumentException("Unknown DocumentSequence entity_type [{$entityType}].");
        }

        $fiscalYear ??= BusinessTime::taxYearOf(BusinessTime::today());

        return DB::transaction(function () use ($tenant, $entityType, $lastIssuedNumber, $fiscalYear) {
            $sequence = DocumentSequence::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('entity_type', $entityType)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->first();

            $nextNumber = $lastIssuedNumber + 1;

            if ($sequence) {
                $sequence->update(['next_number' => $nextNumber]);
            } else {
                $sequence = DocumentSequence::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'entity_type' => $entityType,
                    'prefix' => DocumentSequenceService::PREFIXES[$entityType],
                    'fiscal_year' => $fiscalYear,
                    'next_number' => $nextNumber,
                ]);
            }

            return $sequence->fresh();
        });
    }

    /**
     * §8: "one JournalEntry with eventType = opening_balance ties the
     * whole batch together ... Dr/Cr every account touched by the
     * reconstruction, balanced by Cr/Dr Retained Earnings (Opening) as
     * the plug figure."
     *
     * @param  array<string, Money>  $debitBalances
     * @param  array<string, Money>  $creditBalances
     */
    public function post(OpeningBalanceBatch $batch, array $debitBalances, array $creditBalances, User $postedBy): OpeningBalanceBatch
    {
        if ($batch->status !== 'draft') {
            throw new \DomainException("Cannot post an OpeningBalanceBatch with status '{$batch->status}'.");
        }

        return DB::transaction(function () use ($batch, $debitBalances, $creditBalances, $postedBy) {
            $entryInput = $this->ledger->postOpeningBalance((string) $batch->id, $debitBalances, $creditBalances);
            $entry = $this->ledger->commit($batch->tenant, $entryInput, $batch->as_of_date, $postedBy, $batch->id);

            $batch->update(['status' => 'posted', 'posted_by' => $postedBy->id, 'journal_entry_id' => $entry->id]);

            return $batch->fresh();
        });
    }

    private function assertDraft(OpeningBalanceBatch $batch): void
    {
        if ($batch->status !== 'draft') {
            throw new \DomainException("Cannot add rows to an OpeningBalanceBatch with status '{$batch->status}'.");
        }
    }
}
