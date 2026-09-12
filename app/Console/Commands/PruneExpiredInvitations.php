<?php

namespace App\Console\Commands;

use App\Models\UserInvitation;
use Illuminate\Console\Command;

/**
 * Housekeeping: an invitation nobody accepted before expiring shouldn't
 * linger forever. Scheduled with withoutOverlapping() (routes/console.php)
 * per architecture §1 - the pattern every later scheduled job (retention
 * re-evaluation, depreciation runs, statutory reminders, ...) reuses.
 */
class PruneExpiredInvitations extends Command
{
    protected $signature = 'invitations:prune-expired';

    protected $description = 'Delete UserInvitation rows that expired without being accepted';

    public function handle(): int
    {
        $count = UserInvitation::withoutGlobalScopes()
            ->whereNull('accepted_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$count} expired, unaccepted invitation(s).");

        return self::SUCCESS;
    }
}
