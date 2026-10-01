<?php

namespace App\Services;

use App\Models\FeasibilityAssessment;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.2/§5.1: Quotation/SalesOrder creation, the feasibility gate,
 * approval, and (for Direct Sale/Manufacture-for-Sale) stock
 * reservation. Project/Manufacture-for-Project's Project.status=closed
 * gate is a forward reference - see SalesOrder::canClose().
 */
class SalesOrderService
{
    public function __construct(
        private DocumentSequenceService $sequences,
        private StockAvailabilityService $availability,
    ) {}

    /**
     * @param  array<int, array{item_id?: int, description: string, quantity: string, rate: string}>  $lines
     */
    public function create(Tenant $tenant, array $attributes, array $lines): SalesOrder
    {
        return DB::transaction(function () use ($tenant, $attributes, $lines) {
            $documentNumber = $this->sequences->next($tenant, 'quotation');

            $subtotal = Money::zero();
            $lineModels = [];
            foreach ($lines as $line) {
                $rate = Money::fromMajor($line['rate']);
                $amount = $rate->multiply($line['quantity']);
                $subtotal = $subtotal->add($amount);

                $lineModels[] = [
                    'tenant_id' => $tenant->id,
                    'item_id' => $line['item_id'] ?? null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'rate_cents' => $rate,
                    'amount_cents' => $amount,
                ];
            }

            $salesOrder = SalesOrder::create([
                ...$attributes,
                'tenant_id' => $tenant->id,
                'document_number' => $documentNumber,
                'status' => 'draft',
                'feasibility_status' => 'pending',
                'subtotal_cents' => $subtotal,
                'tax_cents' => Money::zero(),
                'total_cents' => $subtotal,
            ]);

            foreach ($lineModels as $lineModel) {
                $salesOrder->lines()->create($lineModel);
            }

            return $salesOrder->fresh('lines');
        });
    }

    public function submitForFeasibility(SalesOrder $salesOrder): SalesOrder
    {
        if (! in_array($salesOrder->status, ['draft', 'renegotiating'], true)) {
            throw new \DomainException("Cannot submit for feasibility from status '{$salesOrder->status}'.");
        }

        $salesOrder->update(['status' => 'feasibility_check']);

        return $salesOrder->fresh();
    }

    /**
     * §3.2/§5.1: re-assessment is allowed; "latest assessment wins" - the
     * SalesOrder's feasibility_status always reflects the most recently
     * recorded assessment's result, not the first or any aggregate. The
     * three outcomes are exactly the diagram's three branches out of
     * feasibility_check: 'passed' continues straight to 'approved'
     * (the invoice_policy 'on_order' trigger point), 'rejected' is
     * terminal, 'renegotiating' loops back to feasibility_check once
     * the quotation is revised and resubmitted.
     */
    public function recordFeasibilityAssessment(
        SalesOrder $salesOrder,
        string $result,
        ?User $assessedBy = null,
        ?string $notes = null,
        ?string $conditions = null,
    ): FeasibilityAssessment {
        if ($salesOrder->status !== 'feasibility_check') {
            throw new \DomainException("Cannot record a feasibility assessment while status is '{$salesOrder->status}'.");
        }

        if (! in_array($result, ['passed', 'rejected', 'renegotiating'], true)) {
            throw new \InvalidArgumentException("Unknown feasibility result '{$result}'.");
        }

        return DB::transaction(function () use ($salesOrder, $result, $assessedBy, $notes, $conditions) {
            $assessment = FeasibilityAssessment::create([
                'tenant_id' => $salesOrder->tenant_id,
                'sales_order_id' => $salesOrder->id,
                'assessed_by' => $assessedBy?->id,
                'assessed_at' => now(),
                'result' => $result,
                'notes' => $notes,
                'conditions' => $conditions,
            ]);

            $nextStatus = match ($result) {
                'passed' => 'approved',
                'rejected' => 'rejected',
                'renegotiating' => 'renegotiating',
            };

            $salesOrder->update([
                'feasibility_status' => $result,
                'status' => $nextStatus,
                'approved_at' => $result === 'passed' ? now() : null,
            ]);

            // §3.2 invoice_policy trigger: 'on_order' fires on
            // SalesOrder.status=approved, i.e. exactly here when result
            // is 'passed'. Invoice itself is finance-billing's entity
            // (§3.9, not yet built) - the actual raise-and-post-to-ledger
            // step is wired in there against this exact transition; this
            // branch only owns the transition, not the raise.

            return $assessment;
        });
    }

    public function resubmitAfterRenegotiation(SalesOrder $salesOrder): SalesOrder
    {
        if ($salesOrder->status !== 'renegotiating') {
            throw new \DomainException("Cannot resubmit from status '{$salesOrder->status}'.");
        }

        $salesOrder->update(['status' => 'feasibility_check']);

        return $salesOrder->fresh();
    }

    /**
     * Direct Sale/Manufacture-for-Sale: reserve real stock against each
     * line's item, one hard StockReservation per line, wrapping
     * inventory-core's generic stock_reservations table via
     * reference_type=SalesOrder (the call inventory-core's migration
     * left open for this branch to make - see stock_reservations'
     * docblock).
     */
    public function reserveStockForDirectSale(SalesOrder $salesOrder, Warehouse $warehouse): void
    {
        if (! in_array($salesOrder->supply_path, ['direct_sale', 'manufacture_for_sale'], true)) {
            throw new \DomainException("Stock reservation only applies to Direct Sale/Manufacture-for-Sale, not '{$salesOrder->supply_path}'.");
        }

        DB::transaction(function () use ($salesOrder, $warehouse) {
            foreach ($salesOrder->lines as $line) {
                if (! $line->item_id) {
                    continue;
                }

                $this->availability->reserve(
                    $line->item,
                    $warehouse,
                    (string) $line->quantity,
                    'hard',
                    SalesOrder::class,
                    $salesOrder->id,
                );
            }
        });
    }
}
