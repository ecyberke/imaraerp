<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §6: "Reservation writes happen inside a transaction with a
 * pessimistic lock (SELECT ... FOR UPDATE on the relevant stock_ledger
 * rows for that item/warehouse) ... Check-and-reserve is one transaction,
 * not two statements - the availability computation and the reservation
 * write happen inside the same locked transaction, never a 'check
 * availability' read followed by a separate 'reserve' write."
 *
 * "Edge case: the very first reservation against an item has no existing
 * stock_ledger rows to lock. Lock the Item row itself (or use a Postgres
 * advisory lock keyed on item_id/warehouse_id) for that case."
 */
class StockAvailabilityService
{
    public function __construct(private StockValuationService $valuation)
    {
    }

    public function availableQuantity(Item $item, Warehouse $warehouse): string
    {
        $onHand = $this->valuation->onHandQuantity($item, $warehouse);
        $reserved = $this->activeHardReservedQuantity($item, $warehouse);

        return bcsub($onHand, $reserved, 4);
    }

    private function activeHardReservedQuantity(Item $item, Warehouse $warehouse): string
    {
        return (string) StockReservation::withoutGlobalScopes()
            ->where('tenant_id', $item->tenant_id)
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('reserve_type', 'hard')
            ->where('status', 'active')
            ->sum('quantity');
    }

    /**
     * Locks, computes availability, and writes the reservation - all
     * inside one transaction. A 'soft' reservation never blocks on
     * availability (§6/§3.2: "doesn't remove stock from availability,
     * just flags intent") - only 'hard' can fail with insufficient stock.
     */
    public function reserve(
        Item $item,
        Warehouse $warehouse,
        string $quantity,
        string $reserveType,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): StockReservation {
        return DB::transaction(function () use ($item, $warehouse, $quantity, $reserveType, $referenceType, $referenceId) {
            $this->lockForReservation($item, $warehouse);

            if ($reserveType === 'hard') {
                $available = $this->availableQuantity($item, $warehouse);
                if (bccomp($available, $quantity, 4) < 0) {
                    throw new InsufficientStockException($item, $warehouse, $quantity, $available);
                }
            }

            return StockReservation::create([
                'tenant_id' => $item->tenant_id,
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'reserve_type' => $reserveType,
                'status' => 'active',
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        });
    }

    /**
     * SELECT ... FOR UPDATE on the item's existing stock_ledger rows -
     * two concurrent transactions locking the same row set serialize
     * against each other (the second blocks until the first commits or
     * rolls back), which is the actual mutual-exclusion mechanism this
     * whole service depends on. Falls back to a Postgres advisory
     * transaction lock (released automatically at commit/rollback) only
     * for §6's named edge case - no ledger rows yet to lock.
     */
    private function lockForReservation(Item $item, Warehouse $warehouse): void
    {
        // Deliberately ->get() with a deterministic order, not ->exists()
        // or ->first(): under SELECT ... FOR UPDATE wrapped in EXISTS,
        // Postgres can short-circuit on whichever row it finds first,
        // which isn't guaranteed to be the same row across two concurrent
        // transactions' query plans - if they each lock a *different*
        // row, neither actually blocks the other and the whole point of
        // this lock is defeated. Locking the full, consistently-ordered
        // row set (or a stable single "first by id" row every caller
        // locks the same way) is what actually guarantees two concurrent
        // reservations against the same item/warehouse serialize.
        $lockedRows = StockLedger::withoutGlobalScopes()
            ->where('tenant_id', $item->tenant_id)
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lockedRows->isEmpty()) {
            $key = crc32("stock-reserve:{$item->tenant_id}:{$item->id}:{$warehouse->id}");
            DB::select('SELECT pg_advisory_xact_lock(?)', [$key]);
        }
    }
}
