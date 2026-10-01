<?php

namespace App\Console\Commands;

use App\Services\ResourceAssignmentService;
use Illuminate\Console\Command;

/**
 * §3.6: the scheduled half of "scheduled -> active fires automatically
 * when block_start_date is reached ... active -> completed fires the
 * same way against block_end_date." Registered daily in
 * routes/console.php, same pattern as invitations:prune-expired.
 */
class AdvanceResourceAssignmentStatuses extends Command
{
    protected $signature = 'resource-assignments:advance-status';

    protected $description = 'Advance ResourceAssignment rows from scheduled to active, and active to completed, per their block_start_date/block_end_date.';

    public function handle(ResourceAssignmentService $assignments): int
    {
        $count = $assignments->advanceScheduledTransitions();

        $this->info("Advanced {$count} resource assignment(s).");

        return self::SUCCESS;
    }
}
