<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\LandedCost;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §3.4: LandedCost allocated proportionally across a GRN's
 * lines by value. The ledger posting itself is a single aggregate entry
 * (Dr Inventory (RM), Cr Landed Cost Payable for the total) - the
 * per-cost-type/per-line breakdown lives on the LandedCost record, not
 * the JournalLine (§3.1's seed note: JournalLine has no memo field).
 *
 * Scope decision, flagged rather than silently under-delivered: applying
 * the per-line allocated share back onto each GRNLine's own valuation
 * would require either holding the GRN in a pending state before its
 * stock_ledger receipt rows are written, or amending an already-inserted
 * (append-only, §3.3) stock_ledger row - neither is built here. This
 * computes and exposes the proportional split (allocateLandedCostByLine())
 * for audit/reference and posts the correct aggregate entry; applying it
 * back into per-line valuation is real remaining work, not implemented.
 */
class LandedCostAllocationService
{
    public function __construct(private LedgerPostingService $ledger)
    {
    }

    public function allocate(GoodsReceiptNote $grn, string $costType, Money $totalAmount): LandedCost
    {
        return DB::transaction(function () use ($grn, $costType, $totalAmount) {
            $tenant = Tenant::findOrFail($grn->tenant_id);
            $baseCurrency = Currency::withoutGlobalScopes()
                ->where('tenant_id', $grn->tenant_id)->where('is_base', true)->firstOrFail();

            $landedCost = LandedCost::create([
                'tenant_id' => $grn->tenant_id,
                'grn_id' => $grn->id,
                'cost_type' => $costType,
                'amount_cents' => $totalAmount,
                'currency_id' => $baseCurrency->id,
                'exchange_rate' => 1,
            ]);

            $entryInput = $this->ledger->postLandedCostAllocation((string) $landedCost->id, $totalAmount, $costType);
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), referenceId: $landedCost->id);

            return $landedCost;
        });
    }

    /**
     * @return array<int, Money> GRNLine id => allocated share
     */
    public function allocateLandedCostByLine(GoodsReceiptNote $grn, Money $totalAmount): array
    {
        $grn->loadMissing('lines');

        $lineValues = [];
        foreach ($grn->lines as $line) {
            $lineValues[$line->id] = $line->unit_cost->multiply((string) $line->quantity_received);
        }

        return $this->ledger->allocateLandedCostProportionally($lineValues, $totalAmount);
    }
}
