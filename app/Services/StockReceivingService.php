<?php

namespace App\Services;

use App\Models\Item;
use App\Models\QualityCheck;
use App\Models\StockLedger;
use App\Models\StockQuarantine;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Simulates the GRN-receipt -> QC -> stock_ledger -> ledger-core round
 * trip inventory-core's exit criterion (execution_plan.md) names.
 * GoodsReceiptNote itself doesn't exist until procurement - this takes
 * the same inputs a real GRN line will eventually supply (item,
 * warehouse, quantity, actual unit cost) directly, exactly the same
 * "prove the mechanism against a stand-in now, real entity plugs in
 * later" pattern used throughout this project (DummyRecord,
 * postPaymentReceived's allocations array, ...).
 */
class StockReceivingService
{
    public function __construct(
        private LedgerPostingService $ledger,
    ) {
    }

    public function receiveIntoQuarantine(
        Item $item,
        Warehouse $warehouse,
        string $quantity,
        Money $actualUnitCost,
        ?int $grnId = null,
    ): StockQuarantine {
        return StockQuarantine::create([
            'tenant_id' => $item->tenant_id,
            'grn_id' => $grnId,
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $quantity,
            'unit_cost_cents' => $actualUnitCost,
            'qc_status' => 'pending',
        ]);
    }

    /**
     * §3.3: "QualityCheck is the authoritative record ... StockQuarantine
     * .qc_status is a denormalized summary kept in sync by it." §6: "only
     * a passed QC disposition triggers the stock_ledger insert" - read
     * here as result=pass AND disposition=accept together; rework/scrap
     * material never enters available stock regardless of the result
     * field, and a fail+accept combination (or any other mismatch) is
     * nonsensical enough not to move stock either.
     */
    public function recordQualityCheck(
        StockQuarantine $quarantine,
        string $result,
        string $disposition,
        ?User $evaluator = null,
    ): QualityCheck {
        return DB::transaction(function () use ($quarantine, $result, $disposition, $evaluator) {
            $qc = QualityCheck::create([
                'tenant_id' => $quarantine->tenant_id,
                'checkable_type' => StockQuarantine::class,
                'checkable_id' => $quarantine->id,
                'result' => $result,
                'evaluator_id' => $evaluator?->id,
                'disposition' => $disposition,
            ]);

            $quarantine->update(['qc_status' => $result === 'pass' ? 'passed' : 'failed']);

            if ($result === 'pass' && $disposition === 'accept') {
                $this->postAcceptedReceipt($quarantine);
            }

            return $qc;
        });
    }

    /**
     * §3.4: "Which unit cost, resolved per Category.valuation_method:
     * fifo/weighted_average categories record the actual GRN cost;
     * standard_cost categories record Item.standard_cost instead, with
     * the actual-vs-standard difference posting to Purchase Price
     * Variance (§7) rather than affecting the value carried in
     * stock_ledger at all." The quarantine's own unit_cost_cents is
     * always the real, actual received cost (needed for the variance
     * calculation regardless of valuation method) - what lands in
     * stock_ledger differs by category.
     */
    private function postAcceptedReceipt(StockQuarantine $quarantine): void
    {
        $item = Item::withoutGlobalScopes()->with('category')->findOrFail($quarantine->item_id);
        $tenant = Tenant::findOrFail($quarantine->tenant_id);
        $actualUnitCost = $quarantine->unit_cost_cents;
        $isStandardCost = $item->category->valuation_method === 'standard_cost';
        $ledgerUnitCost = $isStandardCost ? ($item->standard_cost ?? Money::zero()) : $actualUnitCost;

        StockLedger::create([
            'tenant_id' => $quarantine->tenant_id,
            'item_id' => $quarantine->item_id,
            'warehouse_id' => $quarantine->warehouse_id,
            'movement_type' => 'receipt',
            'quantity' => $quarantine->quantity,
            'unit_cost_cents' => $ledgerUnitCost,
            'reference_type' => StockQuarantine::class,
            'reference_id' => $quarantine->id,
        ]);

        $actualValue = $actualUnitCost->multiply((string) $quarantine->quantity);

        if ($isStandardCost) {
            $standardValue = $ledgerUnitCost->multiply((string) $quarantine->quantity);
            $entryInput = $this->ledger->postGrnReceiptStandardCost(
                (string) $quarantine->id, $standardValue, $actualValue,
            );
        } else {
            $entryInput = $this->ledger->postGrnReceipt((string) $quarantine->id, $actualValue);
        }

        $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), referenceId: $quarantine->id);
    }
}
