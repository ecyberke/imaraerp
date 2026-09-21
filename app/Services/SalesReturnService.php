<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\StockLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.2 (promoted to Phase 1 per execution_plan.md): a return that only
 * reverses revenue via Credit Note and never touches inventory leaves
 * stock permanently understated - this always writes a real StockLedger
 * 'return' row (signed positive, restocking) alongside the SalesReturn
 * record. Scrapped returns (restock_status='scrapped') deliberately do
 * NOT restock - goods came back damaged/unsellable, so no ledger row is
 * written for them.
 */
class SalesReturnService
{
    public function create(
        Delivery $delivery,
        SalesOrderLine $line,
        string $quantity,
        ?string $reason,
        string $restockStatus = 'restocked',
    ): SalesReturn {
        return DB::transaction(function () use ($delivery, $line, $quantity, $reason, $restockStatus) {
            $return = SalesReturn::create([
                'tenant_id' => $delivery->tenant_id,
                'delivery_id' => $delivery->id,
                'sales_order_line_id' => $line->id,
                'item_id' => $line->item_id,
                'warehouse_id' => $delivery->warehouse_id,
                'quantity_returned' => $quantity,
                'reason' => $reason,
                'restock_status' => $restockStatus,
            ]);

            if ($restockStatus === 'restocked' && $line->item_id) {
                $unitCost = $line->item->standard_cost ?? Money::zero();

                StockLedger::create([
                    'tenant_id' => $delivery->tenant_id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $delivery->warehouse_id,
                    'movement_type' => 'return',
                    'quantity' => $quantity, // positive: restocking increases on-hand
                    'unit_cost_cents' => $unitCost,
                    'reference_type' => SalesReturn::class,
                    'reference_id' => $return->id,
                ]);
            }

            return $return;
        });
    }
}
