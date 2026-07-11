<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon metrics graphs need periodic snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Google ToS: cached Places review snippets must be refreshed or dropped
// after ~30 days (T-059).
Schedule::command('reelmap:google:refresh-stale')->daily()->onOneServer()->withoutOverlapping();
