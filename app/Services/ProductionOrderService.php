<?php

namespace App\Services;

use App\Models\ProductionOrder;
use App\Models\QualityCheck;
use App\Models\StockLedger;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.5/§7: consumes RM per the BOM, produces FG at standard cost, and
 * posts the whole event through LedgerPostingService::postProductionConsumption()
 * in one entry (Dr Inventory FG at standard, Cr Inventory RM at actual,
 * the difference landing in Wastage Variance/Wastage Variance Expense -
 * that variance routing already existed in ledger-core, unexercised
 * until this branch had a real ProductionOrder to call it from).
 */
class ProductionOrderService
{
    public function __construct(
        private StockValuationService $valuation,
        private LedgerPostingService $ledger,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array<int, string>  $actualQuantities  bill_of_material_line_id => actual quantity consumed (major units). Any line omitted defaults to its BOM-planned quantity plus the BOM's own wastage_allowance_pct - "normal, expected wastage happened, nothing to flag."
     */
    public function complete(ProductionOrder $order, array $actualQuantities = [], ?User $completedBy = null): ProductionOrder
    {
        if ($order->status !== 'draft') {
            throw new \DomainException("Cannot complete a ProductionOrder with status '{$order->status}'.");
        }

        return DB::transaction(function () use ($order, $actualQuantities, $completedBy) {
            $order->loadMissing('bom.lines.rawMaterialItem.category', 'bom.finishedGoodItem.category', 'warehouse');
            $bom = $order->bom;
            $tenant = Tenant::findOrFail($order->tenant_id);

            $fgItem = $bom->finishedGoodItem;
            if ($fgItem->category->valuation_method !== 'standard_cost' || ! $fgItem->standard_cost) {
                throw new \DomainException("Item '{$fgItem->name}' has no standard_cost set - a finished good produced via manufacturing must be a standard_cost-category item with a standard_cost value, since production output is always valued at standard (§7).");
            }

            $rmValueActual = Money::zero();
            $rmValueAllowed = Money::zero();

            foreach ($bom->lines as $line) {
                $plannedQty = bcmul((string) $line->quantity, (string) $order->quantity, 4);
                $allowedQty = bcmul($plannedQty, (string) bcadd('1', (string) $bom->wastage_allowance_pct, 6), 4);
                $actualQty = $actualQuantities[$line->id] ?? $allowedQty;

                $lineValue = $this->valuation->costOfIssue($line->rawMaterialItem, $order->warehouse, $actualQty);
                $allowedValue = $this->valuation->costOfIssue($line->rawMaterialItem, $order->warehouse, $allowedQty);

                $rmValueActual = $rmValueActual->add($lineValue);
                $rmValueAllowed = $rmValueAllowed->add($allowedValue);

                StockLedger::create([
                    'tenant_id' => $order->tenant_id,
                    'item_id' => $line->raw_material_item_id,
                    'warehouse_id' => $order->warehouse_id,
                    'movement_type' => 'production_consumption',
                    'quantity' => $actualQty,
                    'unit_cost_cents' => bccomp($actualQty, '0', 4) > 0 ? $lineValue->multiply(bcdiv('1', $actualQty, 10)) : Money::zero(),
                    'reference_type' => ProductionOrder::class,
                    'reference_id' => $order->id,
                ]);
            }

            $fgValueStandard = $fgItem->standard_cost->multiply((string) $order->quantity);

            StockLedger::create([
                'tenant_id' => $order->tenant_id,
                'item_id' => $fgItem->id,
                'warehouse_id' => $order->warehouse_id,
                'movement_type' => 'production_output',
                'quantity' => $order->quantity,
                'unit_cost_cents' => $fgItem->standard_cost,
                'reference_type' => ProductionOrder::class,
                'reference_id' => $order->id,
            ]);

            $entryInput = $this->ledger->postProductionConsumption((string) $order->id, $fgValueStandard, $rmValueActual);
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), $completedBy, $order->id);

            // §3.5's "wastage variance against tolerance_pct" is a
            // quantity/value-overage check distinct from the monetary
            // Wastage Variance ledger account above: how much MORE the
            // actual RM consumption ran over the BOM's own planned
            // allowance (planned quantity x (1 + wastage_allowance_pct)),
            // as a percentage of that allowance.
            $bomVariancePct = $rmValueAllowed->isPositive()
                ? bcdiv($rmValueActual->sub($rmValueAllowed)->cents(), $rmValueAllowed->cents(), 4)
                : '0.0000';
            $exceeded = bccomp($bomVariancePct, (string) $bom->effectiveTolerancePct(), 4) > 0;

            $order->update([
                'status' => 'completed',
                'rm_value_actual_cents' => $rmValueActual,
                'fg_value_standard_cents' => $fgValueStandard,
                'wastage_variance_cents' => $fgValueStandard->sub($rmValueActual),
                'bom_variance_pct' => $bomVariancePct,
                'bom_variance_exceeded' => $exceeded,
                'journal_entry_id' => $entry->id,
                'completed_at' => now(),
            ]);

            // §12 open-decision #3: "record, alert, let a human decide" -
            // bom_variance_exceeded is the record; this is the alert.
            // Warehouse/Procurement are ProductionOrderPolicy's own
            // default owners for this entity (no role named in §11).
            if ($exceeded) {
                $tenant = Tenant::findOrFail($order->tenant_id);
                foreach (['warehouse', 'procurement'] as $role) {
                    $this->notifications->notifyRole(
                        $tenant, $role, 'bom_variance_exceeded',
                        "ProductionOrder #{$order->id} exceeded its BOM wastage tolerance ({$bomVariancePct} actual vs {$bom->effectiveTolerancePct()} allowed).",
                        ProductionOrder::class, $order->id,
                    );
                }
            }

            return $order->fresh();
        });
    }

    /**
     * §3.5: QualityCheck on the FG output - the whole order's produced
     * quantity is one QC decision (QualityCheck carries no quantity
     * field of its own, unlike StockQuarantine's per-receipt row, so a
     * ProductionOrder's output is a single pass/fail lot, not
     * partial-quantity dispositions).
     */
    public function recordQualityCheck(ProductionOrder $order, string $result, string $disposition, ?User $evaluator = null): QualityCheck
    {
        if ($order->status !== 'completed') {
            throw new \DomainException('Cannot record a quality check before the ProductionOrder is completed.');
        }

        return DB::transaction(function () use ($order, $result, $disposition, $evaluator) {
            $qc = QualityCheck::create([
                'tenant_id' => $order->tenant_id,
                'checkable_type' => ProductionOrder::class,
                'checkable_id' => $order->id,
                'result' => $result,
                'evaluator_id' => $evaluator?->id,
                'disposition' => $disposition,
            ]);

            $order->update(['qc_status' => $result === 'pass' ? 'passed' : 'failed']);

            if (in_array($disposition, ['rework', 'scrap'], true)) {
                $order->loadMissing('bom.finishedGoodItem');
                $fgItem = $order->bom->finishedGoodItem;

                StockLedger::create([
                    'tenant_id' => $order->tenant_id,
                    'item_id' => $fgItem->id,
                    'warehouse_id' => $order->warehouse_id,
                    'movement_type' => 'scrap',
                    'quantity' => $order->quantity,
                    'unit_cost_cents' => $fgItem->standard_cost,
                    'reference_type' => QualityCheck::class,
                    'reference_id' => $qc->id,
                ]);

                $entryInput = $this->ledger->postReworkScrapWriteOff((string) $order->id, $order->fg_value_standard);
                $this->ledger->commit($order->tenant, $entryInput, BusinessTime::today(), $evaluator, $qc->id);
            }

            return $qc;
        });
    }
}
