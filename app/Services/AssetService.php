<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.12/§7: acquisition capitalizes directly to the ledger (Dr Fixed
 * Assets at Cost, Cr Accounts Payable) - unlike inventory items, a Fixed
 * Asset isn't procured through the PO/GRN flow, so this service is itself
 * the acquisition event, not a consumer of one.
 */
class AssetService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function acquire(Tenant $tenant, array $data): Asset
    {
        return DB::transaction(function () use ($tenant, $data) {
            $asset = Asset::create([
                ...$data,
                'tenant_id' => $tenant->id,
                'purchase_cost_cents' => Money::fromMajor($data['purchase_cost']),
                'residual_value_cents' => Money::fromMajor($data['residual_value'] ?? '0'),
                'status' => $data['status'] ?? 'in_use',
            ]);

            $entryInput = $this->ledger->postAssetAcquired((string) $asset->id, $asset->purchase_cost);
            $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), null, $asset->id);

            return $asset->fresh();
        });
    }

    /**
     * §3.12: "useful_life_years (mutable) ... a lifespan_change_reason is
     * logged via AuditLog when it changes, not silently overwritten."
     * Enforced here as a required explanation whenever the value actually
     * changes - AuditLogObserver (attached to Asset) does the actual
     * logging once the attribute is saved.
     */
    public function updateLifespan(Asset $asset, int $newUsefulLifeYears, string $reason): Asset
    {
        if ($newUsefulLifeYears === $asset->useful_life_years) {
            throw new \DomainException('New useful_life_years is the same as the current value - nothing to change.');
        }

        $asset->update([
            'useful_life_years' => $newUsefulLifeYears,
            'lifespan_change_reason' => $reason,
            'schedule_reset_at' => BusinessTime::today(),
        ]);

        return $asset->fresh();
    }

    public function updateStatus(Asset $asset, string $status): Asset
    {
        if (! in_array($status, ['in_use', 'under_maintenance'], true)) {
            throw new \InvalidArgumentException('updateStatus() only moves between in_use/under_maintenance - use AssetDisposalService for disposed/written_off.');
        }

        $asset->update(['status' => $status]);

        return $asset->fresh();
    }
}
