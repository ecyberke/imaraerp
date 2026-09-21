<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Support\Money;

/**
 * Architecture §3.3/§6: "On-hand value is always computed by summing this
 * log per the item's category valuation_method, never stored as a
 * running balance." Valuation method changes are effective-dated, never
 * retroactive (§6) - not implemented here as a distinct mechanism since
 * nothing yet lets a category's valuation_method change after items
 * exist with ledger history; the moment that's built, this service is
 * where the effective-dating has to be enforced (walk the ledger under
 * whatever method was in force at each row's date, not the category's
 * current setting applied retroactively).
 */
class StockValuationService
{
    public function onHandQuantity(Item $item, Warehouse $warehouse): string
    {
        $rows = StockLedger::withoutGlobalScopes()
            ->where('tenant_id', $item->tenant_id)
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $quantity = '0.0000';
        foreach ($rows as $row) {
            $quantity = bcadd($quantity, $row->signedQuantity(), 4);
        }

        return $quantity;
    }

    /**
     * Dispatches by the item's category valuation_method (§3.1/§6):
     * - standard_cost: quantity × Item.standard_cost - no ledger walk
     *   needed for the *value*, only the quantity.
     * - weighted_average: a running average recalculated on every IN
     *   movement, reduced proportionally on every OUT.
     * - fifo: a queue of cost layers, IN movements push a layer, OUT
     *   movements consume from the oldest layer(s) first.
     */
    public function onHandValue(Item $item, Warehouse $warehouse): Money
    {
        $method = $item->category->valuation_method;

        return match ($method) {
            'standard_cost' => $this->standardCostValue($item, $warehouse),
            'weighted_average' => $this->weightedAverageValue($item, $warehouse),
            'fifo' => $this->fifoValue($item, $warehouse),
            default => throw new \InvalidArgumentException("Unknown valuation_method '{$method}'."),
        };
    }

    private function standardCostValue(Item $item, Warehouse $warehouse): Money
    {
        $quantity = $this->onHandQuantity($item, $warehouse);
        $standardCost = $item->standard_cost ?? Money::zero();

        return $standardCost->multiply($quantity);
    }

    private function ledgerRows(Item $item, Warehouse $warehouse)
    {
        return StockLedger::withoutGlobalScopes()
            ->where('tenant_id', $item->tenant_id)
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function weightedAverageValue(Item $item, Warehouse $warehouse): Money
    {
        $runningQty = '0.0000';
        $runningValue = Money::zero();

        foreach ($this->ledgerRows($item, $warehouse) as $row) {
            $signed = $row->signedQuantity();

            if (bccomp($signed, '0', 4) > 0) {
                // IN (or a positive adjustment): add this row's own value,
                // recompute the average going forward.
                $runningValue = $runningValue->add($row->unit_cost_cents->multiply($signed));
                $runningQty = bcadd($runningQty, $signed, 4);
            } else {
                // OUT (or a negative adjustment): relieve at the CURRENT
                // running average, not this row's own unit_cost (which
                // for an OUT row is itself derived from this same
                // average at the time it was written).
                $outQty = bcmul($signed, '-1', 4);
                if (bccomp($runningQty, '0', 4) > 0) {
                    $avgCostRatio = bcdiv($outQty, $runningQty, 10);
                    $runningValue = $runningValue->sub($runningValue->multiply($avgCostRatio));
                }
                $runningQty = bcadd($runningQty, $signed, 4);
            }
        }

        return $runningValue;
    }

    /**
     * The cost to attribute to an OUT movement of `quantity` units that
     * hasn't been written to the ledger yet (a Delivery's COGS, §3.2) -
     * dispatches by valuation_method same as onHandValue, but computes
     * the cost of *this* issue rather than the whole remaining balance.
     */
    public function costOfIssue(Item $item, Warehouse $warehouse, string $quantity): Money
    {
        $method = $item->category->valuation_method;

        return match ($method) {
            'standard_cost' => ($item->standard_cost ?? Money::zero())->multiply($quantity),
            'weighted_average' => $this->weightedAverageIssueCost($item, $warehouse, $quantity),
            'fifo' => $this->fifoIssueCost($item, $warehouse, $quantity),
            default => throw new \InvalidArgumentException("Unknown valuation_method '{$method}'."),
        };
    }

    private function weightedAverageIssueCost(Item $item, Warehouse $warehouse, string $quantity): Money
    {
        $onHandQty = $this->onHandQuantity($item, $warehouse);

        if (bccomp($onHandQty, '0', 4) <= 0) {
            return Money::zero();
        }

        $onHandValue = $this->weightedAverageValue($item, $warehouse);

        return $onHandValue->multiply(bcdiv($quantity, $onHandQty, 10));
    }

    private function fifoIssueCost(Item $item, Warehouse $warehouse, string $quantity): Money
    {
        /** @var array<int, array{qty: string, unitCost: Money}> $layers */
        $layers = [];

        foreach ($this->ledgerRows($item, $warehouse) as $row) {
            $signed = $row->signedQuantity();

            if (bccomp($signed, '0', 4) > 0) {
                $layers[] = ['qty' => $signed, 'unitCost' => $row->unit_cost_cents];

                continue;
            }

            $toConsume = bcmul($signed, '-1', 4);
            foreach ($layers as $i => &$layer) {
                if (bccomp($toConsume, '0', 4) <= 0) {
                    break;
                }
                $consumedFromLayer = bccomp($layer['qty'], $toConsume, 4) < 0 ? $layer['qty'] : $toConsume;
                $layer['qty'] = bcsub($layer['qty'], $consumedFromLayer, 4);
                $toConsume = bcsub($toConsume, $consumedFromLayer, 4);
            }
            unset($layer);
            $layers = array_values(array_filter($layers, fn ($l) => bccomp($l['qty'], '0', 4) > 0));
        }

        $toConsume = $quantity;
        $cost = Money::zero();
        foreach ($layers as $layer) {
            if (bccomp($toConsume, '0', 4) <= 0) {
                break;
            }
            $consumedFromLayer = bccomp($layer['qty'], $toConsume, 4) < 0 ? $layer['qty'] : $toConsume;
            $cost = $cost->add($layer['unitCost']->multiply($consumedFromLayer));
            $toConsume = bcsub($toConsume, $consumedFromLayer, 4);
        }

        return $cost;
    }

    private function fifoValue(Item $item, Warehouse $warehouse): Money
    {
        /** @var array<int, array{qty: string, unitCost: Money}> $layers */
        $layers = [];

        foreach ($this->ledgerRows($item, $warehouse) as $row) {
            $signed = $row->signedQuantity();

            if (bccomp($signed, '0', 4) > 0) {
                $layers[] = ['qty' => $signed, 'unitCost' => $row->unit_cost_cents];

                continue;
            }

            $toConsume = bcmul($signed, '-1', 4);
            foreach ($layers as $i => &$layer) {
                if (bccomp($toConsume, '0', 4) <= 0) {
                    break;
                }
                $consumedFromLayer = bccomp($layer['qty'], $toConsume, 4) < 0 ? $layer['qty'] : $toConsume;
                $layer['qty'] = bcsub($layer['qty'], $consumedFromLayer, 4);
                $toConsume = bcsub($toConsume, $consumedFromLayer, 4);
            }
            unset($layer);
            $layers = array_values(array_filter($layers, fn ($l) => bccomp($l['qty'], '0', 4) > 0));
        }

        $total = Money::zero();
        foreach ($layers as $layer) {
            $total = $total->add($layer['unitCost']->multiply($layer['qty']));
        }

        return $total;
    }
}
