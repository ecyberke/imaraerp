<?php

namespace App\Services;

use App\Models\BoqLine;
use App\Models\BoqSection;
use App\Models\Milestone;
use App\Models\MilestoneBoqLineAllocation;
use App\Models\Project;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.7: pending -> utilized -> signed_off -> invoiced -> closed, with
 * rework_required reachable from utilized or signed_off whenever the sign-
 * off/QS review finds the work doesn't yet justify billing. billing_amount
 * is derived, never entered freestanding - recomputed from
 * boq_line_allocations every time an allocation changes.
 */
class MilestoneService
{
    public function create(Project $project, int $sequence, string $description): Milestone
    {
        return Milestone::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'sequence' => $sequence,
            'description' => $description,
            'status' => 'pending',
        ]);
    }

    /**
     * §3.7: "boq_line_allocations - the single primitive." percentage is
     * of that BoqLine's own amount, not of the milestone or the whole BOQ.
     */
    public function allocateLine(Milestone $milestone, int $boqLineId, string $percentageOfValue): MilestoneBoqLineAllocation
    {
        if ($milestone->billing_locked) {
            throw new \DomainException('Cannot change BOQ-line allocations on a Milestone whose billing is locked.');
        }

        $allocation = MilestoneBoqLineAllocation::updateOrCreate(
            ['milestone_id' => $milestone->id, 'boq_line_id' => $boqLineId],
            ['tenant_id' => $milestone->tenant_id, 'percentage_of_value' => $percentageOfValue],
        );

        $this->recomputeBillingAmount($milestone);

        return $allocation;
    }

    /**
     * §3.7: "bulk section-level allocation is a UI shorthand expanding to
     * one row per line at allocation time, not a separate mechanism."
     * Expands to every line in this section and every descendant section,
     * each at the same percentage.
     */
    public function allocateSection(Milestone $milestone, BoqSection $section, string $percentageOfValue): array
    {
        $sectionIds = $this->sectionAndDescendantIds($section);

        $lineIds = BoqLine::whereIn('section_id', $sectionIds)->pluck('id');

        return $lineIds->map(fn (int $lineId) => $this->allocateLine($milestone, $lineId, $percentageOfValue))->all();
    }

    private function sectionAndDescendantIds(BoqSection $section): array
    {
        $ids = [$section->id];

        foreach ($section->childSections as $child) {
            $ids = array_merge($ids, $this->sectionAndDescendantIds($child));
        }

        return $ids;
    }

    public function recomputeBillingAmount(Milestone $milestone): void
    {
        $milestone->loadMissing('boqLineAllocations.boqLine');

        $total = Money::zero();
        foreach ($milestone->boqLineAllocations as $allocation) {
            $share = bcdiv((string) $allocation->percentage_of_value, '100', 6);
            $lineAmountCents = $allocation->boqLine->amount->cents();
            $allocatedCents = (int) bcmul((string) $lineAmountCents, $share, 0);
            $total = $total->add(Money::fromCents($allocatedCents));
        }

        $milestone->update(['billing_amount_cents' => $total]);
    }

    public function markUtilized(Milestone $milestone): Milestone
    {
        if ($milestone->status !== 'pending' && $milestone->status !== 'rework_required') {
            throw new \DomainException("Cannot mark a Milestone with status '{$milestone->status}' as utilized.");
        }

        $milestone->update(['status' => 'utilized']);

        return $milestone->fresh();
    }

    /**
     * §3.7's named concurrency requirement: "two simultaneous sign-offs
     * prevented via a transaction holding a row lock on the Milestone."
     * The second racer, once it acquires the lock, sees the first
     * racer's already-committed status change and is rejected cleanly
     * instead of double-processing the sign-off.
     */
    public function signOff(Milestone $milestone): Milestone
    {
        return DB::transaction(function () use ($milestone) {
            $locked = Milestone::withoutGlobalScopes()->where('id', $milestone->id)->lockForUpdate()->first();

            if ($locked->status !== 'utilized') {
                throw new \DomainException("Cannot sign off a Milestone with status '{$locked->status}'.");
            }

            $locked->update(['status' => 'signed_off']);

            return $locked->fresh();
        });
    }

    public function requireRework(Milestone $milestone, string $reason): Milestone
    {
        if (! in_array($milestone->status, ['utilized', 'signed_off'], true)) {
            throw new \DomainException("Cannot require rework on a Milestone with status '{$milestone->status}'.");
        }

        $milestone->update(['status' => 'rework_required']);

        return $milestone->fresh();
    }

    public function markInvoiced(Milestone $milestone): Milestone
    {
        if ($milestone->status !== 'signed_off') {
            throw new \DomainException("Cannot invoice a Milestone with status '{$milestone->status}'.");
        }

        $milestone->update(['status' => 'invoiced', 'billing_locked' => true]);

        return $milestone->fresh();
    }

    public function close(Milestone $milestone): Milestone
    {
        if ($milestone->status !== 'invoiced') {
            throw new \DomainException("Cannot close a Milestone with status '{$milestone->status}'.");
        }

        $milestone->update(['status' => 'closed']);

        return $milestone->fresh();
    }
}
