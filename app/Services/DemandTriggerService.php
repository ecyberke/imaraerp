<?php

namespace App\Services;

use App\Models\DemandTrigger;
use App\Models\Item;
use App\Models\Warehouse;

/**
 * Architecture §3.4: "Reorder Level checks read the same ledger" (§6) -
 * a scheduled job (or ledger-insert trigger) compares computed on-hand
 * against Item.reorder_level and raises a DemandTrigger automatically.
 * "Only raise a new DemandTrigger if no DemandTrigger with status still
 * open already exists for that item/warehouse" - the explicit
 * deduplication rule this class exists to enforce.
 */
class DemandTriggerService
{
    public function __construct(private StockValuationService $valuation, private NotificationService $notifications) {}

    /**
     * quantity_needed's exact formula isn't specified in the doc beyond
     * "raises a DemandTrigger" - reorder_level minus on-hand (order
     * enough to bring stock back up to the threshold) is the simplest
     * defensible default, not a documented requirement.
     */
    public function checkAndRaise(Item $item, Warehouse $warehouse): ?DemandTrigger
    {
        $onHand = $this->valuation->onHandQuantity($item, $warehouse);

        if (bccomp($onHand, (string) $item->reorder_level, 4) >= 0) {
            return null;
        }

        $existing = DemandTrigger::where('tenant_id', $item->tenant_id)
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return $existing;
        }

        $trigger = DemandTrigger::create([
            'tenant_id' => $item->tenant_id,
            'source_type' => 'reorder_level',
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity_needed' => bcsub((string) $item->reorder_level, $onHand, 4),
            'status' => 'open',
        ]);

        $this->notifications->notifyRole(
            $item->tenant, 'procurement', 'reorder_alert',
            "{$item->name} at {$warehouse->name} has fallen below its reorder level - {$trigger->quantity_needed} needed.",
            DemandTrigger::class, $trigger->id,
        );

        return $trigger;
    }
}
