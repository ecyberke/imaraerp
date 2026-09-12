<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() on every scheduled job (architecture §1) - the
// pattern every later scheduled job (retention re-evaluation, monthly
// depreciation, statutory reminders, ...) reuses from here on.
Schedule::command('invitations:prune-expired')->daily()->withoutOverlapping();
