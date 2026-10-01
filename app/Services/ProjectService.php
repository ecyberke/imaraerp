<?php

namespace App\Services;

use App\Models\BoqLine;
use App\Models\ContractRetentionTerms;
use App\Models\Defect;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RetentionAccount;
use App\Models\RetentionRelease;
use App\Models\SalesOrder;
use App\Models\Subcontract;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;

/**
 * §3.7/§5.4: initiated -> in_progress -> complete -> defects_liability ->
 * closed, plus cancelled (terminal, from any pre-closed state).
 *
 * §5.4's "complete -> defects_liability transition itself is scheduled" is
 * ambiguous about which exact step the scheduled job performs. Resolved
 * here as: complete -> defects_liability happens synchronously inside
 * markComplete() (the liability period starts immediately at completion,
 * matching real construction practice), while the scheduled job
 * (evaluateDlpEligibility(), routes/console.php:
 * projects:evaluate-dlp-eligibility) only ever evaluates whether DLP has
 * *elapsed* (ContractRetentionTerms.dlp_duration_months against
 * completed_at) AND no blocking Defect remains open/in_progress, setting
 * dlp_ready_to_close - which then gates the separate MANUAL
 * closeAfterDefectsLiability() confirmation. Both the job and the manual
 * close respect Project.version optimistic locking, per §5.4's explicitly
 * named race (DLP-eligibility-job vs manual-closure both touching the same
 * Project row).
 */
class ProjectService
{
    public function __construct(private WriteOffService $writeOffs) {}

    public function create(Tenant $tenant, array $data): Project
    {
        return Project::create([...$data, 'tenant_id' => $tenant->id, 'status' => 'initiated']);
    }

    public function start(Project $project): Project
    {
        if ($project->status !== 'initiated') {
            throw new \DomainException("Cannot start a Project with status '{$project->status}'.");
        }

        $project->update(['status' => 'in_progress', 'version' => $project->version + 1]);

        return $project->fresh();
    }

    public function markComplete(Project $project): Project
    {
        if ($project->status !== 'in_progress') {
            throw new \DomainException("Cannot complete a Project with status '{$project->status}'.");
        }

        $project->update([
            'status' => 'defects_liability',
            'completed_at' => BusinessTime::today(),
            'version' => $project->version + 1,
        ]);

        return $project->fresh();
    }

    /**
     * The scheduled half of the DLP transition (routes/console.php,
     * daily). Re-reads each Project's version inside its own row lock
     * before acting, so a manual closeAfterDefectsLiability() that races
     * this job never gets silently overwritten.
     */
    public function evaluateDlpEligibility(): int
    {
        $evaluated = 0;

        $projectIds = Project::withoutGlobalScopes()->where('status', 'defects_liability')->pluck('id');

        foreach ($projectIds as $projectId) {
            DB::transaction(function () use ($projectId, &$evaluated) {
                $project = Project::withoutGlobalScopes()->where('id', $projectId)->lockForUpdate()->first();

                if (! $project || $project->status !== 'defects_liability' || ! $project->completed_at) {
                    return;
                }

                $terms = $this->retentionTermsFor($project);
                $dlpMonths = $terms?->dlp_duration_months ?? 12;
                $dlpElapsed = BusinessTime::today()->gte($project->completed_at->copy()->addMonths($dlpMonths));

                $hasBlockingDefect = Defect::withoutGlobalScopes()
                    ->where('tenant_id', $project->tenant_id)
                    ->where('project_id', $project->id)
                    ->where('blocks_retention', true)
                    ->whereIn('status', Defect::BLOCKING_STATUSES)
                    ->exists();

                $readyToClose = $dlpElapsed && ! $hasBlockingDefect;

                if ($readyToClose !== (bool) $project->dlp_ready_to_close) {
                    $project->update(['dlp_ready_to_close' => $readyToClose, 'version' => $project->version + 1]);
                    $evaluated++;
                }
            });
        }

        return $evaluated;
    }

    /**
     * The manual half: defects_liability -> closed. Requires
     * dlp_ready_to_close (the job's own eligibility flag) and the caller's
     * expected version to still match - a mismatch means the job (or
     * another request) already touched this Project since the caller last
     * read it, and it must re-read and retry rather than blindly overwrite.
     */
    public function closeAfterDefectsLiability(Project $project, int $expectedVersion): Project
    {
        return DB::transaction(function () use ($project, $expectedVersion) {
            $locked = Project::withoutGlobalScopes()->where('id', $project->id)->lockForUpdate()->first();

            if ($locked->version !== $expectedVersion) {
                throw new \DomainException('This Project has changed since it was loaded - reload and retry.');
            }

            if ($locked->status !== 'defects_liability') {
                throw new \DomainException("Cannot close a Project with status '{$locked->status}'.");
            }

            if (! $locked->dlp_ready_to_close) {
                throw new \DomainException('This Project is not yet ready to close - the Defects Liability Period has not elapsed or a blocking Defect remains open.');
            }

            $locked->update(['status' => 'closed', 'version' => $locked->version + 1]);

            return $locked->fresh();
        });
    }

    /**
     * §5.4's documented obligation-handling on cancellation:
     * - unbilled Milestones: written off (concrete - WriteOffService).
     * - pending/blocked RetentionRelease: requires an explicit early-
     *   release decision already made (concrete - RetentionRelease's own
     *   status vocabulary and release()'s early_release_reason gate).
     * - open Subcontract/ProgressClaim "settled or disputed per own
     *   status", open PurchaseOrders "cancelled or received-against-
     *   normally": NOT enforced here - neither Subcontract nor
     *   ProgressClaim has a defined status vocabulary for "settled" or
     *   "disputed" anywhere in this codebase (procurement's own branch
     *   never formalized one), and PurchaseOrder::STATUSES has no
     *   'cancelled' value at all. Enforcing this would mean inventing a
     *   cross-module state machine outside this branch's scope - flagged
     *   here rather than silently guessed at, same as the ApprovalLimit
     *   gap on VariationOrder.
     */
    public function cancel(Project $project, string $reason, User $cancelledBy): Project
    {
        if ($project->status === 'closed' || $project->status === 'cancelled') {
            throw new \DomainException("Cannot cancel a Project with status '{$project->status}'.");
        }

        return DB::transaction(function () use ($project, $reason, $cancelledBy) {
            $this->assertNoPendingRetentionReleaseWithoutDecision($project);

            $unbilledMilestones = Milestone::where('project_id', $project->id)
                ->whereNotIn('status', ['invoiced', 'closed'])
                ->get();

            foreach ($unbilledMilestones as $milestone) {
                $this->writeOffs->writeOffMilestone($milestone, "Project #{$project->id} cancelled: {$reason}", $cancelledBy);
            }

            $project->update(['status' => 'cancelled', 'version' => $project->version + 1]);

            return $project->fresh();
        });
    }

    /**
     * §5.4: completion% = sum(Milestone.billing_amount where status in
     * {signed_off, invoiced, closed}) / Boq.revised_contract_value when
     * invoice_policy=on_milestone; otherwise (measurement-driven) =
     * sum(BoqLine.amount for lines with a certified MeasurementSheet) /
     * Boq.revised_contract_value. Returns a decimal string (bcmath scale
     * 4), e.g. "0.4500" for 45%.
     */
    public function completionPercentage(Project $project): string
    {
        $boq = $project->boq;
        $salesOrder = $this->primarySalesOrder($project);

        if (! $boq) {
            return '0.0000';
        }

        $contractValueCents = $boq->revised_contract_value->cents();

        if ($contractValueCents <= 0) {
            return '0.0000';
        }

        if ($salesOrder?->invoice_policy === 'on_milestone') {
            $numeratorCents = Milestone::where('project_id', $project->id)
                ->whereIn('status', ['signed_off', 'invoiced', 'closed'])
                ->get()
                ->sum(fn (Milestone $m) => (int) $m->billing_amount->cents());
        } else {
            $numeratorCents = BoqLine::where('boq_id', $boq->id)
                ->whereHas('measurementSheets', fn ($q) => $q->where('status', 'certified'))
                ->get()
                ->sum(fn (BoqLine $l) => (int) $l->amount->cents());
        }

        return bcdiv((string) $numeratorCents, (string) $contractValueCents, 4);
    }

    private function primarySalesOrder(Project $project): ?SalesOrder
    {
        return SalesOrder::where('project_id', $project->id)
            ->whereIn('supply_path', ['project', 'manufacture_for_project'])
            ->orderBy('id')
            ->first();
    }

    private function retentionTermsFor(Project $project): ?ContractRetentionTerms
    {
        $salesOrder = $this->primarySalesOrder($project);

        if (! $salesOrder) {
            return null;
        }

        return ContractRetentionTerms::withoutGlobalScopes()
            ->where('contract_type', SalesOrder::class)
            ->where('contract_id', $salesOrder->id)
            ->first();
    }

    private function assertNoPendingRetentionReleaseWithoutDecision(Project $project): void
    {
        $salesOrderIds = SalesOrder::where('project_id', $project->id)->pluck('id');
        $subcontractIds = Subcontract::where('project_id', $project->id)->pluck('id');

        $accountIds = RetentionAccount::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where(function ($query) use ($salesOrderIds, $subcontractIds) {
                $query->where(function ($q) use ($salesOrderIds) {
                    $q->where('contract_type', SalesOrder::class)->whereIn('contract_id', $salesOrderIds);
                })->orWhere(function ($q) use ($subcontractIds) {
                    $q->where('contract_type', Subcontract::class)->whereIn('contract_id', $subcontractIds);
                });
            })
            ->pluck('id');

        $blocked = RetentionRelease::withoutGlobalScopes()
            ->whereIn('retention_account_id', $accountIds)
            ->whereIn('status', ['pending', 'blocked_defects', 'blocked_client_signoff', 'blocked_dispute'])
            ->exists();

        if ($blocked) {
            throw new \DomainException('This Project has a pending or blocked RetentionRelease that requires an explicit early-release decision before it can be cancelled.');
        }
    }
}
