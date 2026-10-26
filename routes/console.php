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
