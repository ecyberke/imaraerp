<?php

namespace App\Services;

use App\Models\Defect;
use App\Models\RetentionAccount;
use App\Models\RetentionRelease;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.9: two-stage by design (practical_completion, then dlp_end). The
 * automatic pending->ready re-evaluation the doc describes depended on
 * Defect (§3.7, blocks_retention), client sign-off tracking, and dispute
 * records - Defect now exists (projects-milestones-ui) and its
 * blocks_retention gate is wired into markReady() below; client sign-off
 * and dispute tracking still don't exist anywhere in this codebase, so
 * 'blocked_client_signoff'/'blocked_dispute' remain reachable statuses
 * with no automatic producer - still a manual override until those
 * entities exist.
 */
class RetentionReleaseService
{
    public function __construct(private LedgerPostingService $ledger, private NotificationService $notifications) {}

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

    /**
     * §3.7: "second-stage release cannot reach 'ready' while a
     * blocks_retention=true Defect on that project/subcontract is open
     * or in_progress." Re-evaluates every time it's called rather than
     * being cached, since a Defect can flip in/out of the blocking set
     * at any time.
     */
    public function markReady(RetentionRelease $release): RetentionRelease
    {
        $wasReady = $release->status === 'ready';
        $blocked = $this->hasBlockingDefect($release->retentionAccount);

        $release->update([
            'status' => $blocked ? 'blocked_defects' : 'ready',
            'block_reason' => $blocked ? 'One or more open/in-progress Defects on this project or subcontract block retention release.' : null,
        ]);

        // §3.10: "genuinely urgent types ... retention release ready -
        // always stay immediate." Finance owns RetentionRelease (§11).
        if (! $blocked && ! $wasReady) {
            $this->notifications->notifyRole(
                $release->tenant, 'finance', 'retention_release_due',
                "RetentionRelease #{$release->id} ({$release->stage}) is ready to release.",
                RetentionRelease::class, $release->id,
            );
        }

        return $release->fresh();
    }

    /**
     * RetentionAccount.contract_type/contract_id names the SalesOrder
     * (client retention) or Subcontract (subcontractor retention) that
     * carries the retention - neither stores project_id directly on the
     * RetentionAccount itself, so it's resolved through whichever
     * contract this one is.
     */
    private function hasBlockingDefect(RetentionAccount $account): bool
    {
        $projectId = null;
        $subcontractId = null;

        if ($account->contract_type === SalesOrder::class) {
            $projectId = SalesOrder::withoutGlobalScopes()->find($account->contract_id)?->project_id;
        } elseif ($account->contract_type === Subcontract::class) {
            $subcontract = Subcontract::withoutGlobalScopes()->find($account->contract_id);
            $projectId = $subcontract?->project_id;
            $subcontractId = $subcontract?->id;
        }

        if (! $projectId && ! $subcontractId) {
            return false;
        }

        return Defect::withoutGlobalScopes()
            ->where('tenant_id', $account->tenant_id)
            ->where(function ($query) use ($projectId, $subcontractId) {
                if ($projectId) {
                    $query->orWhere('project_id', $projectId);
                }
                if ($subcontractId) {
                    $query->orWhere('subcontract_id', $subcontractId);
                }
            })
            ->where('blocks_retention', true)
            ->whereIn('status', Defect::BLOCKING_STATUSES)
            ->exists();
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
