<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetRevaluation;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.12/v8's self-caught fix: net_book_value_at_revaluation and
 * accumulated_depreciation_at_revaluation are stored once, at the moment
 * of revaluation - never recomputed later.
 *
 * The ledger posting (postAssetRevaluedUpward/Downward) never touches
 * Accumulated Depreciation - it adjusts Fixed Assets at Cost by the delta
 * instead (Dr/Cr Asset Revaluation Reserve, or P&L once the reserve is
 * exhausted on the way down). This means Asset.purchase_cost_cents is
 * updated by the same delta, not set to new_valuation outright - that
 * keeps `purchase_cost - accumulated_depreciation` (unchanged) correctly
 * equal to new_valuation going forward, matching what the ledger itself
 * now shows for Fixed Assets at Cost. Accumulated depreciation is
 * deliberately left alone (both in the Asset row and in
 * AssetDepreciationEntry history) - the ledger doesn't reduce it, so
 * neither does this.
 */
class AssetRevaluationService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function revalue(
        Asset $asset,
        string $newValuationMajor,
        ?string $newResidualValueMajor,
        ?int $newUsefulLifeYears,
        string $reason,
        User $approvedBy,
    ): AssetRevaluation {
        return DB::transaction(function () use ($asset, $newValuationMajor, $newResidualValueMajor, $newUsefulLifeYears, $reason, $approvedBy) {
            $accumulated = $asset->currentAccumulatedDepreciation();
            $nbvAtRevaluation = $asset->purchase_cost->sub($accumulated);
            $newValuation = Money::fromMajor($newValuationMajor);
            $delta = $newValuation->sub($nbvAtRevaluation);

            $journalEntry = null;
            $newPurchaseCost = $asset->purchase_cost;

            if ($delta->isPositive()) {
                $entryInput = $this->ledger->postAssetRevaluedUpward(
                    revaluationReference: (string) $asset->id,
                    purchaseCost: $asset->purchase_cost,
                    accumulatedDepreciationAtRevaluation: $accumulated,
                    newValuation: $newValuation,
                );
                $journalEntry = $this->ledger->commit($asset->tenant, $entryInput, BusinessTime::today(), $approvedBy, $asset->id);
                $newPurchaseCost = $asset->purchase_cost->add($delta);
            } elseif ($delta->isNegative()) {
                $entryInput = $this->ledger->postAssetRevaluedDownward(
                    revaluationReference: (string) $asset->id,
                    purchaseCost: $asset->purchase_cost,
                    accumulatedDepreciationAtRevaluation: $accumulated,
                    newValuation: $newValuation,
                    existingRevaluationReserve: $this->existingRevaluationReserve($asset),
                );
                $journalEntry = $this->ledger->commit($asset->tenant, $entryInput, BusinessTime::today(), $approvedBy, $asset->id);
                $newPurchaseCost = $asset->purchase_cost->sub($delta->abs());
            }

            $revaluation = AssetRevaluation::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'revaluation_date' => BusinessTime::today(),
                'net_book_value_at_revaluation_cents' => $nbvAtRevaluation,
                'accumulated_depreciation_at_revaluation_cents' => $accumulated,
                'new_valuation_cents' => $newValuation,
                'new_residual_value_cents' => $newResidualValueMajor !== null ? Money::fromMajor($newResidualValueMajor) : null,
                'new_useful_life_years' => $newUsefulLifeYears,
                'reason' => $reason,
                'approved_by' => $approvedBy->id,
                'journal_entry_id' => $journalEntry?->id,
            ]);

            $asset->update([
                'purchase_cost_cents' => $newPurchaseCost,
                'residual_value_cents' => $newResidualValueMajor !== null ? Money::fromMajor($newResidualValueMajor) : $asset->residual_value,
                'useful_life_years' => $newUsefulLifeYears ?? $asset->useful_life_years,
                'schedule_reset_at' => BusinessTime::today(),
            ]);

            return $revaluation->fresh();
        });
    }

    /**
     * Replays this asset's own revaluation history to find the running
     * Asset Revaluation Reserve balance just before the one being
     * recorded now - a downward revaluation only hits P&L once this
     * asset's own prior upward reserve is exhausted, never a shared pool
     * across other assets.
     */
    private function existingRevaluationReserve(Asset $asset): Money
    {
        $reserve = Money::zero();

        foreach ($asset->revaluations()->orderBy('revaluation_date')->get() as $prior) {
            $priorDelta = $prior->new_valuation->sub($prior->net_book_value_at_revaluation);

            if ($priorDelta->isPositive()) {
                $reserve = $reserve->add($priorDelta);
            } else {
                $consumed = $priorDelta->abs()->lessThan($reserve) ? $priorDelta->abs() : $reserve;
                $reserve = $reserve->sub($consumed);
            }
        }

        return $reserve;
    }
}
