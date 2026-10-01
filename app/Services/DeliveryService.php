<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockLedger;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.2/§5.1/§7: Delivery/DeliveryLine mirror GRNLine's partial-fulfillment
 * shape. Each line posts a real StockLedger 'issue' row (stock physically
 * leaves) costed via StockValuationService::costOfIssue, consumes the
 * matching hard StockReservation, and rolls the SalesOrder up to
 * partially_delivered/delivered.
 */
class DeliveryService
{
    public function __construct(private StockValuationService $valuation) {}

    public function createDelivery(SalesOrder $salesOrder, Warehouse $warehouse): Delivery
    {
        return Delivery::create([
            'tenant_id' => $salesOrder->tenant_id,
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
        ]);
    }

    public function addLine(Delivery $delivery, SalesOrderLine $line, string $quantity): DeliveryLine
    {
        return DB::transaction(function () use ($delivery, $line, $quantity) {
            $delivery->loadMissing('warehouse');

            $cogs = Money::zero();

            // A line with no item_id (e.g. a BOQ provisional-sum/labour
            // line) has no physical stock to move - stock_ledger.item_id
            // is NOT NULL, so no row is written for it, and its COGS is
            // zero rather than a StockLedger constraint violation.
            if ($line->item_id) {
                $cogs = $this->valuation->costOfIssue($line->item, $delivery->warehouse, $quantity);
                $unitCost = $cogs->multiply(bcdiv('1', $quantity, 10));

                StockLedger::create([
                    'tenant_id' => $delivery->tenant_id,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $delivery->warehouse_id,
                    'movement_type' => 'issue',
                    'quantity' => $quantity,
                    'unit_cost_cents' => $unitCost,
                    'reference_type' => Delivery::class,
                    'reference_id' => $delivery->id,
                ]);
            }

            $deliveryLine = DeliveryLine::create([
                'tenant_id' => $delivery->tenant_id,
                'delivery_id' => $delivery->id,
                'sales_order_line_id' => $line->id,
                'quantity_delivered' => $quantity,
                'cogs_value_cents' => $cogs,
            ]);

            $line->update(['quantity_delivered' => bcadd((string) $line->quantity_delivered, $quantity, 4)]);

            $this->consumeReservation($line, $quantity);

            return $deliveryLine;
        });
    }

    private function consumeReservation(SalesOrderLine $line, string $quantity): void
    {
        if (! $line->item_id) {
            return;
        }

        $remaining = $quantity;

        $reservations = StockReservation::withoutGlobalScopes()
            ->where('tenant_id', $line->tenant_id)
            ->where('reference_type', SalesOrder::class)
            ->where('reference_id', $line->sales_order_id)
            ->where('item_id', $line->item_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $consume = bccomp($reservation->quantity, $remaining, 4) < 0 ? $reservation->quantity : $remaining;
            $newQuantity = bcsub((string) $reservation->quantity, $consume, 4);
            $remaining = bcsub($remaining, $consume, 4);

            if (bccomp($newQuantity, '0', 4) <= 0) {
                $reservation->update(['quantity' => '0.0000', 'status' => 'consumed']);
            } else {
                $reservation->update(['quantity' => $newQuantity]);
            }
        }
    }

    /**
     * §5.1: 'delivered' fires the on_delivery invoice_policy trigger
     * point - the actual Invoice raise is finance-billing's job, not
     * built here (see SalesOrderService's identical note on 'approved').
     */
    public function markDelivered(Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($delivery) {
            $delivery->update(['status' => 'delivered', 'delivered_at' => now()]);

            $this->refreshSalesOrderDeliveryStatus($delivery->salesOrder);

            return $delivery->fresh();
        });
    }

    /**
     * §5.1: partially_delivered while at least one line is short of its
     * ordered quantity but some has shipped; delivered once every line's
     * quantity_delivered meets or exceeds its quantity.
     */
    private function refreshSalesOrderDeliveryStatus(SalesOrder $salesOrder): void
    {
        $salesOrder->loadMissing('lines');

        $anyDelivered = false;
        $allFullyDelivered = true;

        foreach ($salesOrder->lines as $line) {
            $delivered = (string) $line->quantity_delivered;
            if (bccomp($delivered, '0', 4) > 0) {
                $anyDelivered = true;
            }
            if (bccomp($delivered, (string) $line->quantity, 4) < 0) {
                $allFullyDelivered = false;
            }
        }

        if ($allFullyDelivered && $anyDelivered) {
            $salesOrder->update(['status' => 'delivered']);
        } elseif ($anyDelivered) {
            $salesOrder->update(['status' => 'partially_delivered']);
        }
    }
}
