<?php

namespace App\Services;

use App\Models\Defect;
use App\Models\Project;
use App\Models\User;
use App\Support\BusinessTime;

/**
 * §3.7: open -> in_progress -> rectified -> verified -> closed.
 * blocks_retention is "set at review" - left mutable rather than fixed at
 * creation, since severity/retention impact is a judgment call the
 * reviewer makes, not something the reporter necessarily knows.
 */
class DefectService
{
    public function report(Project $project, array $data, User $reportedBy): Defect
    {
        return Defect::create([
            'tenant_id' => $project->tenant_id,
            'project_id' => $project->id,
            'subcontract_id' => $data['subcontract_id'] ?? null,
            'milestone_id' => $data['milestone_id'] ?? null,
            'description' => $data['description'],
            'severity' => $data['severity'],
            'blocks_retention' => $data['blocks_retention'] ?? false,
            'reported_by' => $reportedBy->id,
            'reported_date' => BusinessTime::today(),
            'target_fix_date' => $data['target_fix_date'] ?? null,
            'status' => 'open',
        ]);
    }

    public function setBlocksRetention(Defect $defect, bool $blocksRetention): Defect
    {
        $defect->update(['blocks_retention' => $blocksRetention]);

        return $defect->fresh();
    }

    public function advance(Defect $defect, string $status): Defect
    {
        $order = array_flip(Defect::STATUSES);

        if (! array_key_exists($status, $order) || $order[$status] < $order[$defect->status]) {
            throw new \DomainException("Cannot move a Defect from '{$defect->status}' to '{$status}'.");
        }

        $defect->update(['status' => $status]);

        return $defect->fresh();
    }
}
