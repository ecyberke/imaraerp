<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/** §3.10: digest batching for lower-priority Notification types. */
class DispatchNotificationDigests extends Command
{
    protected $signature = 'notifications:dispatch-digests';

    protected $description = 'Dispatch every due batched_daily/batched_weekly Notification.';

    public function handle(NotificationService $notifications): int
    {
        $count = $notifications->dispatchDueDigests();

        $this->info("Dispatched {$count} digest notification(s).");

        return self::SUCCESS;
    }
}
