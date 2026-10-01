<?php

namespace App\Services;

use App\Models\RetentionAccount;
use App\Models\RetentionRelease;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: two-stage by design (practical_completion, then dlp_end). The
 * automatic pending->ready re-evaluation the doc describes depends on
 * Defect (§3.7, blocks_retention), client sign-off tracking, and dispute
 * records - none of which exist yet in this codebase (Defect belongs to
 * a later branch). markReady() here is a manual stand-in for that
 * scheduled job, flagged rather than silently built as if automatic;
 * the real job replaces this call once those entities exist.
 * early_release_reason is enforced as required whenever release() fires
 * from any status other than 'ready' - the documented "manual override,
 * not an edge case" path.
 */
class RetentionReleaseService
{
    public function __construct(private LedgerPostingService $ledger) {}

    public function request(RetentionAccount $account, string $stage, string $amountMajor): RetentionRelease
    {
        return RetentionRelease::create([
            'tenant_id' => $account->tenant_id,
            'retention_account_id' => $account->id,
            'stage' => $stage,
            'amount_cents' => Money::fromMajor($amountMajor),
            'status' => 'pending',
        ]);
    }

    public function markReady(RetentionRelease $release): RetentionRelease
    {
        $release->update(['status' => 'ready']);

        return $release->fresh();
    }

    public function release(RetentionRelease $release, ?User $releasedBy = null, ?string $earlyReleaseReason = null): RetentionRelease
    {
        if ($release->status === 'released') {
            throw new \DomainException('This RetentionRelease has already been released.');
        }

        if ($release->status !== 'ready' && ! $earlyReleaseReason) {
            throw new \DomainException("Releasing from status '{$release->status}' requires an early_release_reason.");
        }

        return DB::transaction(function () use ($release, $releasedBy, $earlyReleaseReason) {
            $account = $release->retentionAccount;

            $entryInput = $account->direction === 'receivable'
                ? $this->ledger->postRetentionReleasedClient((string) $release->id, $release->amount)
                : $this->ledger->postRetentionReleasedSubcontractor((string) $release->id, $release->amount);

            $entry = $this->ledger->commit($account->tenant, $entryInput, BusinessTime::today(), $releasedBy, $release->id);

            $release->update([
                'status' => 'released',
                'released_at' => now(),
                'journal_entry_id' => $entry->id,
                'early_release_reason' => $earlyReleaseReason,
            ]);

            $account->update([
                'released_amount_cents' => $account->released_amount->add($release->amount),
            ]);

            return $release->fresh();
        });
    }
}
