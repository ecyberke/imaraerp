<?php

namespace App\Console\Commands;

use App\Services\ProjectService;
use Illuminate\Console\Command;

/**
 * §5.4: the scheduled half of the defects_liability -> closed transition -
 * checks whether ContractRetentionTerms.dlp_duration_months has elapsed
 * since Project.completed_at and no blocking Defect remains open, setting
 * dlp_ready_to_close. The actual closed transition stays a manual
 * confirmation (ProjectController::closeAfterDefectsLiability). Daily,
 * same pattern as invitations:prune-expired and
 * resource-assignments:advance-status.
 */
class EvaluateDlpEligibility extends Command
{
    protected $signature = 'projects:evaluate-dlp-eligibility';

    protected $description = 'Re-evaluate every Project in defects_liability for dlp_ready_to_close (DLP duration elapsed, no blocking Defect open).';

    public function handle(ProjectService $projects): int
    {
        $count = $projects->evaluateDlpEligibility();

        $this->info("Evaluated DLP eligibility, {$count} project(s) changed.");

        return self::SUCCESS;
    }
}
