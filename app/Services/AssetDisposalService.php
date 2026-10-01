<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetDisposal;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class AssetDisposalService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function dispose(Asset $asset, string $disposalType, ?string $saleProceedsMajor, bool $soldOnCredit = false): AssetDisposal
    {
        if (in_array($asset->status, ['disposed', 'written_off'], true)) {
            throw new \DomainException("Asset is already '{$asset->status}'.");
        }

        return DB::transaction(function () use ($asset, $disposalType, $saleProceedsMajor, $soldOnCredit) {
            $accumulated = $asset->currentAccumulatedDepreciation();
            $nbvAtDisposal = $asset->purchase_cost->sub($accumulated);
            $saleProceeds = $saleProceedsMajor !== null ? Money::fromMajor($saleProceedsMajor) : Money::zero();

            $entryInput = $this->ledger->postAssetDisposed(
                disposalReference: (string) $asset->id,
                purchaseCost: $asset->purchase_cost,
                accumulatedDepreciationAtDisposal: $accumulated,
                saleProceeds: $saleProceeds,
                soldOnCredit: $soldOnCredit,
            );
            $journalEntry = $this->ledger->commit($asset->tenant, $entryInput, BusinessTime::today(), null, $asset->id);

            $disposal = AssetDisposal::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'disposal_date' => BusinessTime::today(),
                'disposal_type' => $disposalType,
                'sale_proceeds_cents' => $saleProceeds,
                'sold_on_credit' => $soldOnCredit,
                'net_book_value_at_disposal_cents' => $nbvAtDisposal,
                'gain_loss_amount_cents' => $saleProceeds->sub($nbvAtDisposal),
                'status' => 'completed',
                'journal_entry_id' => $journalEntry->id,
            ]);

            $asset->update(['status' => $disposalType === 'written_off' ? 'written_off' : 'disposed']);

            return $disposal->fresh();
        });
    }
}
