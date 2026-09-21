<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockLedger;
use App\Models\SupplierReturn;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Post-acceptance supplier return (§3.4/§7): stock that already passed
 * QC and was accepted, returned later - distinct from the QC-failure
 * return path, which never touches stock_ledger at all (§6). This one
 * does, as a negative-quantity 'return' movement (see StockLedger's own
 * docblock for why 'return' is bidirectional here).
 */
class SupplierReturnService
{
    public function __construct(private LedgerPostingService $ledger)
    {
    }

    public function returnToSupplier(
        Item $item,
        Warehouse $warehouse,
        string $quantity,
        Money $unitCost,
        bool $alreadyPaid = false,
    ): SupplierReturn {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $unitCost, $alreadyPaid) {
            $tenant = Tenant::findOrFail($item->tenant_id);

            $return = SupplierReturn::create([
                'tenant_id' => $item->tenant_id,
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'unit_cost_cents' => $unitCost,
                'already_paid' => $alreadyPaid,
            ]);

            StockLedger::create([
                'tenant_id' => $item->tenant_id,
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'movement_type' => 'return',
                'quantity' => bcmul($quantity, '-1', 4),
                'unit_cost_cents' => $unitCost,
                'reference_type' => SupplierReturn::class,
                'reference_id' => $return->id,
            ]);

            $amount = $unitCost->multiply($quantity);
            $entryInput = $this->ledger->postPostAcceptanceSupplierReturn((string) $return->id, $amount, $alreadyPaid);
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), referenceId: $return->id);

            $return->update(['journal_entry_id' => $entry->id]);

            return $return->fresh();
        });
    }
}
