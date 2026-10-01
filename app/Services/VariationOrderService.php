<?php

namespace App\Services;

use App\Models\BoqLine;
use App\Models\BoqSection;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Models\VariationOrder;
use App\Models\VariationOrderLine;
use App\Models\VariationOrderReversal;
use App\Models\VariationOrderReversalLine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * §3.7: draft -> submitted -> (approved -> executed) | rejected.
 * approved -> executed is a separate manual confirmation (site work
 * actually done) distinct from commercial approval.
 *
 * §12 open-decision #7 says VariationOrder approval "reuses ApprovalLimit,
 * thresholded by amount_delta" - but ApprovalLimit doesn't exist yet
 * (deferred to approval-notification-compliance, a later Phase 2 branch).
 * approve()/reject() below take an explicit $approver (and optional
 * $secondApprover) instead of looking ApprovalLimit up, the same forward-
 * reference-parameter pattern ProgressClaimService used for
 * ContractRetentionTerms before that existed either - once ApprovalLimit
 * ships, the lookup replaces the caller-supplied approver, not the other
 * way round.
 */
class VariationOrderService
{
    public function __construct(private MilestoneService $milestones) {}

    public function create(Project $project, string $description): VariationOrder
    {
        return DB::transaction(function () use ($project, $description) {
            $locked = Project::withoutGlobalScopes()->where('id', $project->id)->lockForUpdate()->first();

            $nextSequence = VariationOrder::withoutGlobalScopes()->where('project_id', $locked->id)->count() + 1;

            return VariationOrder::create([
                'tenant_id' => $locked->tenant_id,
                'project_id' => $locked->id,
                'variation_number' => sprintf('VO-%d-%03d', $locked->id, $nextSequence),
                'description' => $description,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * §3.7: quantity_delta/rate_delta/amount_delta are independent inputs
     * on the line, not derived by this service from the BoqLine's current
     * live rate - the caller (QS/PM, via the UI) is the source of truth
     * for what changed and by how much, same as how
     * MilestoneBoqLineAllocation.percentage_of_value is entered directly
     * rather than computed.
     */
    public function addLine(VariationOrder $vo, array $data): VariationOrderLine
    {
        if ($vo->status !== 'draft') {
            throw new \DomainException('Cannot add lines to a VariationOrder that is no longer draft.');
        }

        return VariationOrderLine::create([
            'tenant_id' => $vo->tenant_id,
            'variation_order_id' => $vo->id,
            'boq_line_id' => $data['boq_line_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'variation_type' => $data['variation_type'],
            'quantity_delta' => $data['quantity_delta'] ?? 0,
            'rate_delta_cents' => Money::fromMajor($data['rate_delta'] ?? '0'),
            'amount_delta_cents' => Money::fromMajor($data['amount_delta']),
            'description' => $data['description'],
        ]);
    }

    public function submit(VariationOrder $vo): VariationOrder
    {
        if ($vo->status !== 'draft') {
            throw new \DomainException("Cannot submit a VariationOrder with status '{$vo->status}'.");
        }

        $vo->loadMissing('lines');
        $totalDelta = Money::sum(...$vo->lines->map(fn (VariationOrderLine $l) => $l->amount_delta)->all());

        $vo->update([
            'status' => 'submitted',
            'amount_delta_cents' => $totalDelta,
            'submitted_date' => now(),
        ]);

        return $vo->fresh();
    }

    public function reject(VariationOrder $vo): VariationOrder
    {
        if ($vo->status !== 'submitted') {
            throw new \DomainException("Cannot reject a VariationOrder with status '{$vo->status}'.");
        }

        $vo->update(['status' => 'rejected']);

        return $vo->fresh();
    }

    /**
     * §3.7's named concurrency requirement: "row lock on affected BoqLine/
     * BoqSection rows during approval; a second VO touching an already-
     * locked line must queue or fail with an explicit retry message." This
     * implements the "queue" half - lockForUpdate() blocks the second
     * approval until the first commits, then it proceeds against the
     * now-updated line rather than losing either VO's delta. Lines are
     * locked in ascending boq_line_id order so two VOs touching an
     * overlapping set of lines can never deadlock against each other.
     */
    public function approve(VariationOrder $vo, User $approver, ?User $secondApprover = null): VariationOrder
    {
        if ($vo->status !== 'submitted') {
            throw new \DomainException("Cannot approve a VariationOrder with status '{$vo->status}'.");
        }

        return DB::transaction(function () use ($vo, $approver, $secondApprover) {
            $vo->loadMissing('lines');

            $this->applyLines($vo->lines);
            $this->recalculateBoqAndMilestones($vo->project);

            $vo->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'second_approved_by' => $secondApprover?->id,
                'approved_date' => now(),
            ]);

            return $vo->fresh();
        });
    }

    public function execute(VariationOrder $vo): VariationOrder
    {
        if ($vo->status !== 'approved') {
            throw new \DomainException("Cannot execute a VariationOrder with status '{$vo->status}'.");
        }

        $vo->update(['status' => 'executed', 'executed_date' => now()]);

        return $vo->fresh();
    }

    /**
     * §3.7: "a compensating record ... with its own line rows carrying the
     * negative of the original deltas" - a real, persisted reversal
     * (immutable audit trail), not a delete/edit of the original VO or its
     * lines.
     */
    public function reverse(VariationOrder $vo, string $reason, User $reversedBy): VariationOrderReversal
    {
        if (! in_array($vo->status, ['approved', 'executed'], true)) {
            throw new \DomainException("Cannot reverse a VariationOrder with status '{$vo->status}'.");
        }

        return DB::transaction(function () use ($vo, $reason, $reversedBy) {
            $vo->loadMissing('lines');

            $reversal = VariationOrderReversal::create([
                'tenant_id' => $vo->tenant_id,
                'variation_order_id' => $vo->id,
                'reason' => $reason,
                'reversed_by' => $reversedBy->id,
                'reversed_at' => now(),
            ]);

            $negatedLines = collect();
            foreach ($vo->lines as $line) {
                $negatedLines->push(VariationOrderReversalLine::create([
                    'tenant_id' => $vo->tenant_id,
                    'variation_order_reversal_id' => $reversal->id,
                    'boq_line_id' => $line->boq_line_id,
                    'section_id' => $line->section_id,
                    'variation_type' => $line->variation_type,
                    'quantity_delta' => bcmul((string) $line->quantity_delta, '-1', 4),
                    'rate_delta_cents' => $line->rate_delta->negate(),
                    'amount_delta_cents' => $line->amount_delta->negate(),
                    'description' => "Reversal of {$vo->variation_number}: {$line->description}",
                ]));
            }

            $this->applyLines($negatedLines);
            $this->recalculateBoqAndMilestones($vo->project);

            return $reversal->fresh();
        });
    }

    /**
     * @param  iterable<VariationOrderLine|VariationOrderReversalLine>  $lines
     */
    private function applyLines(iterable $lines): void
    {
        $lines = collect($lines)->sortBy(fn ($l) => $l->boq_line_id ?? 0);

        foreach ($lines as $line) {
            if ($line->boq_line_id) {
                $boqLine = BoqLine::withoutGlobalScopes()->where('id', $line->boq_line_id)->lockForUpdate()->firstOrFail();

                $boqLine->update([
                    'quantity' => bcadd((string) $boqLine->quantity, (string) $line->quantity_delta, 4),
                    'rate_cents' => $boqLine->rate->add($line->rate_delta),
                    'amount_cents' => $boqLine->amount->add($line->amount_delta),
                ]);
            } else {
                // variation_type=new_item: a wholly new BoqLine, inserted
                // into the section the VO line names.
                BoqLine::create([
                    'tenant_id' => $line->tenant_id,
                    'boq_id' => BoqSection::withoutGlobalScopes()->findOrFail($line->section_id)->boq_id,
                    'section_id' => $line->section_id,
                    'description' => $line->description,
                    'unit' => 'item',
                    'quantity' => $line->quantity_delta,
                    'rate_cents' => $line->rate_delta,
                    'amount_cents' => $line->amount_delta,
                    'line_type' => 'measured',
                ]);
            }
        }
    }

    /**
     * §3.7: recalculates Boq.revised_contract_value (sum of lines plus all
     * Markup entries, post-markup) and billing_amount for every Milestone
     * with billing_locked=false in the project.
     */
    private function recalculateBoqAndMilestones(Project $project): void
    {
        $boq = $project->boq()->first();

        if (! $boq) {
            return;
        }

        $boq->loadMissing('lines', 'markups');

        $lineTotal = Money::sum(...$boq->lines->map(fn (BoqLine $l) => $l->amount)->all());
        $markupTotal = Money::zero();
        foreach ($boq->markups as $markup) {
            $markupTotal = $markupTotal->add($lineTotal->multiplyByRate(bcdiv((string) $markup->percentage, '100', 6)));
        }

        $boq->update(['revised_contract_value_cents' => $lineTotal->add($markupTotal)]);

        foreach (Milestone::where('project_id', $project->id)->where('billing_locked', false)->get() as $milestone) {
            $this->milestones->recomputeBillingAmount($milestone);
        }
    }
}
