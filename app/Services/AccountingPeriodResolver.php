<?php

namespace App\Services;

use App\Exceptions\NoOpenAccountingPeriodException;
use App\Models\AccountingPeriod;
use App\Models\Tenant;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;

/**
 * Architecture §3.10's backdating rule, implemented exactly as resolved
 * there (not the execution_plan.md exit-criterion's looser "rejected"
 * wording - the architecture doc is the source of truth when the two
 * disagree, and §3.10 is explicit that postings are never rejected
 * outright: "outright rejection just pushes finance staff toward
 * off-system workarounds"):
 *
 *   1. Find the period whose date range actually contains the intended
 *      date ("the intended period").
 *   2. If that period exists AND is open, post there directly - even if a
 *      different, earlier period happens to also be open. Multiple periods
 *      can legitimately be open at once (routine during year-end close);
 *      "is the intended period itself open" is the only question that
 *      matters here.
 *   3. Otherwise (no such period, or it's locked/closed), auto-forward:
 *      post into the earliest currently-open period instead, and record
 *      the original intended date so nothing is silently lost.
 *
 * 'locked' is treated the same as 'closed' for this purpose - the
 * architecture doc names the auto-forward trigger explicitly only for
 * 'closed' and doesn't otherwise define what 'locked' blocks; blocking new
 * postings the same way 'closed' does is the safe reading absent a
 * documented distinction, and worth confirming rather than assuming.
 */
class AccountingPeriodResolver
{
    public function resolve(Tenant $tenant, CarbonInterface $intendedDate): AccountingPeriodResolution
    {
        $intendedPeriod = $this->periodContaining($tenant, $intendedDate);

        if ($intendedPeriod && $intendedPeriod->isOpen()) {
            return new AccountingPeriodResolution($intendedPeriod, $intendedDate, null);
        }

        $earliestOpen = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if (! $earliestOpen) {
            throw new NoOpenAccountingPeriodException($tenant);
        }

        // The doc doesn't pin what the forwarded posting_date itself
        // becomes - only that the entry lands in the earliest open period
        // with the original intent recorded. Using "now" (business time)
        // reflects that the (re)posting is actually happening at the
        // moment it's forwarded, which is the most defensible reading
        // without a documented alternative.
        return new AccountingPeriodResolution($earliestOpen, BusinessTime::today(), $intendedDate);
    }

    private function periodContaining(Tenant $tenant, CarbonInterface $date): ?AccountingPeriod
    {
        return AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }
}
