<?php

namespace App\Services;

use App\Models\CapitalMovement;
use App\Models\Party;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CapitalMovementService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function record(
        Tenant $tenant,
        string $direction,
        string $amountMajor,
        ?Party $party = null,
        ?\Carbon\CarbonInterface $movementDate = null,
    ): CapitalMovement {
        if (! in_array($direction, CapitalMovement::DIRECTIONS, true)) {
            throw new \InvalidArgumentException("Unknown CapitalMovement direction '{$direction}'.");
        }

        return DB::transaction(function () use ($tenant, $direction, $amountMajor, $party, $movementDate) {
            $amount = Money::fromMajor($amountMajor);

            $movement = CapitalMovement::create([
                'tenant_id' => $tenant->id,
                'direction' => $direction,
                'amount_cents' => $amount,
                'party_id' => $party?->id,
                'movement_date' => $movementDate ?? BusinessTime::today(),
            ]);

            $entryInput = $this->ledger->postCapitalMovement((string) $movement->id, $direction, $amount);
            $entry = $this->ledger->commit($tenant, $entryInput, $movement->movement_date, null, $movement->id);

            $movement->update(['journal_entry_id' => $entry->id]);

            return $movement->fresh();
        });
    }
}
