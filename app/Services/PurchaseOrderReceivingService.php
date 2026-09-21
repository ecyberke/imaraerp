<?php

namespace App\Services;

use App\Models\GoodsReceiptNote;
use App\Models\GRNLine;
use App\Models\PurchaseOrderLine;
use App\Models\QualityCheck;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The real GRN/GRNLine layer on top of inventory-core's
 * StockReceivingService (which only had StockQuarantine to work with,
 * since GoodsReceiptNote didn't exist yet). Every posting/valuation rule
 * that service already implements (standard-cost variance,
 * pass+accept-only stock_ledger insert, ledger-core posting) is reused
 * as-is here - this layer only adds the GRN/GRNLine/PurchaseOrder
 * bookkeeping around it.
 */
class PurchaseOrderReceivingService
{
    public function __construct(private StockReceivingService $receiving)
    {
    }

    /**
     * §3.4: "stock_ledger.unit_cost is always recorded in base currency
     * (quantity x rate x exchange_rate)" - the line's own unit_cost is in
     * the PO's currency; multiplied by the PO's exchange_rate here to get
     * the actual base-currency cost this GRNLine (and the StockQuarantine
     * row underneath it) records.
     */
    public function receiveLine(
        PurchaseOrderLine $line,
        GoodsReceiptNote $grn,
        string $quantity,
        ?\App\Models\Warehouse $warehouse = null,
    ): GRNLine {
        return DB::transaction(function () use ($line, $grn, $quantity, $warehouse) {
            $line->loadMissing('purchaseOrder', 'item');

            $baseCurrencyUnitCost = $line->unit_cost->multiply((string) $line->purchaseOrder->exchange_rate);

            $quarantine = $this->receiving->receiveIntoQuarantine(
                $line->item, $warehouse ?? $this->defaultWarehouse($line), $quantity, $baseCurrencyUnitCost, $grn->id,
            );

            $grnLine = GRNLine::create([
                'tenant_id' => $line->tenant_id,
                'grn_id' => $grn->id,
                'purchase_order_line_id' => $line->id,
                'item_id' => $line->item_id,
                'quantity_received' => $quantity,
                'unit_cost_cents' => $baseCurrencyUnitCost,
                'quarantine_status' => 'pending',
                'stock_quarantine_id' => $quarantine->id,
            ]);

            $grn->refreshQuarantineStatus();
            $line->purchaseOrder->refreshReceivingStatus();

            return $grnLine;
        });
    }

    public function recordQualityCheck(
        GRNLine $grnLine,
        string $result,
        string $disposition,
        ?User $evaluator = null,
    ): QualityCheck {
        return DB::transaction(function () use ($grnLine, $result, $disposition, $evaluator) {
            $grnLine->loadMissing('stockQuarantine', 'grn.purchaseOrder');

            $qc = $this->receiving->recordQualityCheck($grnLine->stockQuarantine, $result, $disposition, $evaluator);

            $grnLine->update(['quarantine_status' => $result === 'pass' ? 'passed' : 'failed']);
            $grnLine->grn->refreshQuarantineStatus();
            $this->refreshPurchaseOrderQcStatus($grnLine->grn->purchaseOrder);

            return $qc;
        });
    }

    /**
     * §5.2's state machine is sequential: ordered -> partially_received
     * -> received -> quarantined -> (qc_passed -> stocked | qc_failed ->
     * returned). The qc_passed/stocked/qc_failed summary only applies
     * once the PO has actually reached 'received' - while still
     * 'partially_received', QC results on the lines received so far must
     * not overwrite that status (a real bug this fixes: the very first
     * GRN's QC pass was flipping the whole PO straight to 'stocked' even
     * though most of the order hadn't arrived yet).
     */
    private function refreshPurchaseOrderQcStatus(\App\Models\PurchaseOrder $po): void
    {
        $po->refresh();

        if ($po->status !== 'received') {
            return;
        }

        $statuses = GRNLine::withoutGlobalScopes()
            ->whereIn('grn_id', $po->grns()->pluck('id'))
            ->pluck('quarantine_status')
            ->unique();

        if ($statuses->isEmpty() || $statuses->contains('pending')) {
            return; // nothing to summarize yet, or still awaiting QC
        }

        $po->update(['status' => $statuses->contains('failed') ? 'qc_failed' : 'stocked']);
    }

    private function defaultWarehouse(PurchaseOrderLine $line): \App\Models\Warehouse
    {
        return \App\Models\Warehouse::withoutGlobalScopes()
            ->where('tenant_id', $line->tenant_id)->where('is_default', true)->firstOrFail();
    }
}
