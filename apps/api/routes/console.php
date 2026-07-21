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

// T-082: keep cached Trustpilot summaries fresh within their own window.
// A no-op unless the Trustpilot source is enabled + keyed.
Schedule::command('reelmap:trustpilot:refresh-stale')->daily()->onOneServer()->withoutOverlapping();

// T-098: publish the best guess for uncertain shares whose confirm step was
// abandoned (shared + closed the app), so nothing dead-ends in review.
Schedule::command('reelmap:reviews:publish-abandoned')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
