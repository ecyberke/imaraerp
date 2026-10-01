<?php

namespace App\Services;

use App\Models\StatutoryRemittance;
use App\Models\Tenant;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.11: "Closes a real gap - every PayrollRun and every subcontractor
 * WHT posting creates a payable to one of these authorities, and nothing
 * in the model previously reduced it." Same clearing pattern as the
 * "Net pay disbursed" row.
 */
class StatutoryRemittanceService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function record(Tenant $tenant, string $period, string $authority, string $amountMajor): StatutoryRemittance
    {
        if (! array_key_exists($authority, StatutoryRemittance::AUTHORITY_ACCOUNTS)) {
            throw new \InvalidArgumentException("Unknown StatutoryRemittance authority '{$authority}'.");
        }

        return StatutoryRemittance::create([
            'tenant_id' => $tenant->id,
            'period' => $period,
            'authority' => $authority,
            'amount_cents' => Money::fromMajor($amountMajor),
            'status' => 'pending',
        ]);
    }

    public function pay(StatutoryRemittance $remittance, string $referenceNumber): StatutoryRemittance
    {
        if ($remittance->status !== 'pending') {
            throw new \DomainException("This StatutoryRemittance is already '{$remittance->status}'.");
        }

        return DB::transaction(function () use ($remittance, $referenceNumber) {
            $account = StatutoryRemittance::AUTHORITY_ACCOUNTS[$remittance->authority];

            $entryInput = $this->ledger->postStatutoryRemittance((string) $remittance->id, $account, $remittance->amount);
            $journalEntry = $this->ledger->commit($remittance->tenant, $entryInput, BusinessTime::today(), null, $remittance->id);

            $remittance->update([
                'status' => 'paid',
                'reference_number' => $referenceNumber,
                'paid_at' => now(),
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $remittance->fresh();
        });
    }
}
