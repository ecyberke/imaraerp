<?php

namespace App\Services;

use App\Models\ProgressClaim;
use App\Models\TaxCode;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Architecture §3.4: WHT is calculated and posted at certification, not
 * deferred to payment. retentionPercentage is an explicit parameter here
 * rather than looked up from ContractRetentionTerms - that entity is
 * §3.9/finance-billing's, built after this branch; the real lookup
 * replaces this parameter once it exists, same forward-reference pattern
 * used throughout.
 */
class ProgressClaimService
{
    public function __construct(private LedgerPostingService $ledger)
    {
    }

    public function certify(
        ProgressClaim $claim,
        Money $amountCertified,
        string $vatRate,
        string $retentionPercentage,
        ?User $certifier = null,
    ): ProgressClaim {
        return DB::transaction(function () use ($claim, $amountCertified, $vatRate, $retentionPercentage, $certifier) {
            $claim->loadMissing('subcontract.party');
            $tenant = Tenant::findOrFail($claim->tenant_id);

            $whtTaxCode = $this->resolveWhtTaxCode($claim->subcontract->party);
            $whtRate = (string) $whtTaxCode->rate;

            $entryInput = $this->ledger->postProgressClaimCertified(
                (string) $claim->id, $amountCertified, $vatRate, $retentionPercentage, $whtRate,
            );
            $entry = $this->ledger->commit($tenant, $entryInput, BusinessTime::today(), $certifier, $claim->id);

            $vatAmount = $amountCertified->multiplyByRate($vatRate);
            $retentionAmount = $amountCertified->multiplyByRate($retentionPercentage);
            $vatOnRetention = $retentionAmount->multiplyByRate($vatRate);
            $whtAmount = $amountCertified->multiplyByRate($whtRate);
            $netPayable = $amountCertified->add($vatAmount)->sub($retentionAmount)->sub($vatOnRetention)->sub($whtAmount);

            $claim->update([
                'amount_certified_cents' => $amountCertified,
                'vat_amount_cents' => $vatAmount,
                'retention_amount_cents' => $retentionAmount,
                'wht_tax_code_id' => $whtTaxCode->id,
                'wht_amount_cents' => $whtAmount,
                'net_payable_cents' => $netPayable,
                'status' => 'certified',
                'journal_entry_id' => $entry->id,
            ]);

            return $claim->fresh();
        });
    }

    /**
     * §3.4: "certified -> reversed posts a mirror-image reversing
     * JournalEntry ... the claim can be re-certified as a fresh row once
     * resolved rather than editing the disputed one in place." Creating
     * that fresh row is a normal certify() call on a new ProgressClaim -
     * no special method needed for it.
     */
    public function reverse(ProgressClaim $claim, ?User $reverser = null): ProgressClaim
    {
        return DB::transaction(function () use ($claim, $reverser) {
            $originalEntry = $claim->journalEntry()->withoutGlobalScopes()->firstOrFail();
            $this->ledger->postProgressClaimReversed($originalEntry);

            $claim->update(['status' => 'reversed']);

            return $claim->fresh();
        });
    }

    /**
     * §3.9: WHT on contractor payments varies by payee -
     * resident-with-certificate 3%, resident-without-certificate 5%,
     * non-resident 20% (platform-foundation's TaxCodeSeeder).
     */
    private function resolveWhtTaxCode(\App\Models\Party $party): TaxCode
    {
        $code = match ($party->tax_residency_status) {
            'resident_certified' => 'WHT_RESIDENT_3',
            'resident_uncertified' => 'WHT_RESIDENT_5',
            'non_resident' => 'WHT_NONRESIDENT_20',
            default => throw new \InvalidArgumentException(
                "Party #{$party->id} has no tax_residency_status set - required to resolve a WHT TaxCode."
            ),
        };

        return TaxCode::withoutGlobalScopes()
            ->where('tenant_id', $party->tenant_id)->where('code', $code)->firstOrFail();
    }
}
