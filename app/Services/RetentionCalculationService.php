<?php

namespace App\Services;

use App\Models\ContractRetentionTerms;
use App\Models\Invoice;
use App\Models\ProgressClaim;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Support\Money;

/**
 * §3.9: ContractRetentionTerms.retention_cap enforcement - "cumulative
 * retention held is checked against the cap on every ProgressClaim/
 * Invoice; once the sum would exceed it, only cap − cumulative_held is
 * withheld on that transaction, and once cumulative equals the cap -
 * the boundary is inclusive, not 'greater than' - all later claims
 * withhold zero until release."
 */
class RetentionCalculationService
{
    public function findTerms(string $contractType, int $contractId): ?ContractRetentionTerms
    {
        return ContractRetentionTerms::where('contract_type', $contractType)
            ->where('contract_id', $contractId)
            ->first();
    }

    /**
     * Given the naive (percentage x amount) retention figure, returns
     * what should actually be withheld on this transaction once the
     * contract's cap (if any) is applied. No terms, or terms with no
     * cap, means no capping - the naive amount is withheld in full.
     */
    public function applyCap(?ContractRetentionTerms $terms, Money $naiveRetentionAmount): Money
    {
        if (! $terms || $terms->retention_cap === null) {
            return $naiveRetentionAmount;
        }

        $cumulativeHeld = $this->cumulativeHeld($terms->contract_type, $terms->contract_id);
        $headroom = $terms->retention_cap->sub($cumulativeHeld);

        if ($headroom->isNegative() || $headroom->isZero()) {
            return Money::zero();
        }

        return $headroom->lessThan($naiveRetentionAmount) ? $headroom : $naiveRetentionAmount;
    }

    /**
     * Deliberately queries Invoice.retention_amount_cents /
     * ProgressClaim.retention_amount_cents directly - the PURE retention
     * figure, excluding VAT-on-retention - rather than summing
     * RetentionAccount.amount_cents, which stores retention + VAT-on-
     * retention combined (correct for that table's own purpose: matching
     * the ledger's actual Retention Receivable/Payable balance, but the
     * wrong figure to compare against a cap expressed on withheld
     * contract value). Conflating the two would under-count headroom by
     * the VAT portion on every transaction after the first.
     */
    private function cumulativeHeld(string $contractType, int $contractId): Money
    {
        if ($contractType === SalesOrder::class) {
            $rows = Invoice::withoutGlobalScopes()
                ->where('sales_order_id', $contractId)
                ->whereNotIn('status', ['draft'])
                ->get();

            return Money::sum(...$rows->map(fn (Invoice $i) => $i->retention_amount)->all());
        }

        if ($contractType === Subcontract::class) {
            $rows = ProgressClaim::withoutGlobalScopes()
                ->where('subcontract_id', $contractId)
                ->where('status', 'certified')
                ->get();

            return Money::sum(...$rows->map(fn (ProgressClaim $c) => $c->retention_amount)->all());
        }

        throw new \InvalidArgumentException("Unknown contract_type '{$contractType}' for retention cap enforcement.");
    }
}
