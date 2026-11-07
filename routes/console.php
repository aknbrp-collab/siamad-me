<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the scheduler running in production: `* * * * * php artisan schedule:run` (see docs/OPERATIONS.md).
Schedule::command('siakad:backup')
    ->dailyAt((string) config('siakad.backup.time'))
    ->withoutOverlapping();

// Work due within 24 hours and bills due in 7 days / today; each reminder is sent once per person.
Schedule::command('siakad:send-reminders')->hourly()->withoutOverlapping();

// v5: what the website editors decided about each article sent to the CMS.
// Expired API tokens (devices not used for SANCTUM_TOKEN_DAYS).
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:30');

Schedule::command('siakad:cms-check')->dailyAt('06:00')->withoutOverlapping();

// v5 D32: the queue (CMS calls and their retries) is worked every minute, so no separate worker
// service is needed. A permanent `php artisan queue:work` under Supervisor works too (docs/OPERATIONS.md).
Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->withoutOverlapping();
