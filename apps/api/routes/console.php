<?php

use App\Support\RetentionWindow;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

// T-043 / 06 §2.3: only a REDEEMED code is billable, so an unvisited one must
// not sit at `issued` looking like an open obligation. Hygiene only — the
// verify path re-checks the clock, so the window between a code lapsing and
// this run is safe, not merely unlikely.
Schedule::command('reelmap:redemptions:expire')->hourly()->onOneServer()->withoutOverlapping();

// T-044 / 02 §3.15: assert the books balance. LedgerService refuses to write an
// imbalance, so this should never find anything — which is the point. A silent
// arithmetic failure in the ledger is the one bug that does not announce itself.
Schedule::command('reelmap:ledger:verify')->dailyAt('03:30')->onOneServer()->withoutOverlapping();

// T-127 / 06 §2.2: audit the offer quota counter cache against the redemption
// rows. Report-only — a repair (`--fix`) is a human decision, because drift
// means a writer is wrong and silently correcting it every night would hide the
// bug rather than surface it. Runs after the ledger check: same nightly window,
// same reason, and the two are read together when either fails.
Schedule::command('reelmap:offers:reconcile-quotas')
    ->dailyAt('03:45')
    ->onOneServer()
    ->withoutOverlapping()
    // The command exits non-zero when it finds something, and `schedule:run`
    // throws that code away. Without this the whole point of the exit code — a
    // run that visibly failed rather than a log line nobody greps for — reaches
    // nobody at all. The findings themselves are already structured log records
    // (`offer.quota_counter_drift`, `offer.quota_slots_held_by_lapsed_codes`);
    // this is the one line that says the nightly audit came back unhappy.
    ->onFailure(fn () => Log::error('offer.quota_reconcile_failed', [
        'command' => 'reelmap:offers:reconcile-quotas',
    ]));

// T-158: rebuild the open-hours projection nightly.
//
// `PlaceObserver` maintains `place_open_periods` on every write, and on failure
// it logs `open_periods.materialize_failed` and lets the enrichment succeed —
// derived data must not take down the write that produced it. That leaves drift
// with no way back: the observer's own comment concedes "a retry only heals this
// by accident, when the next write happens to touch the same columns", and the
// deploy-time backfill only runs at a deploy.
//
// The drift is invisible from both ends, which is what makes a schedule the
// right answer rather than a nicety. The place keeps showing a correct "Open"
// cue on its detail screen — that is computed in PHP from the jsonb — while
// being absent from every `?open_now=1` listing. Nobody looking at either
// surface can tell, and Tonight is exactly the surface that would quietly stop
// showing a venue that is open.
//
// Same nightly window as the two audits above and the same `onFailure` line, for
// the same reason: `schedule:run` discards the exit code, so without it a
// backfill that failed reaches nobody.
Schedule::command('reelmap:open-periods:backfill --fail-on-drift')
    // 04:45, after `gdpr:prune-exports`, not 04:00 beside the audits: this is
    // the only O(corpus) job in the nightly cluster — one short transaction per
    // place — so it is the one that would still be running when
    // `sources:prune-payloads` starts at 04:10. The window stays serial.
    ->dailyAt('04:45')
    ->onOneServer()
    // An explicit expiry, unlike its neighbours. The default is 1440 minutes,
    // which for a daily job means a SIGKILL or an OOM leaves a lock that expires
    // at the same minute the next run fires — and `releaseOnTerminationSignals`
    // covers SIGTERM, not 137. This is the longest-running job on the schedule
    // and therefore the likeliest to be killed.
    ->withoutOverlapping(120)
    // `--fail-on-drift` makes the exit code mean "the walk had to repair a place
    // that carries hours" — see the command for the half it cannot see — and
    // this line is what carries that anywhere: `schedule:run` throws exit codes
    // away.
    ->onFailure(fn () => Log::error('open_periods.backfill_failed', [
        'command' => 'reelmap:open-periods:backfill',
    ]));

// T-050: the fail-safe behind account deletion. The delayed PurgeUserData job
// is the fast path, not the guarantee — a flushed Redis or a failed job is an
// erasure that silently never happens, and nothing else would ever notice.
// The database knows what is owed; this asks it.
Schedule::command('reelmap:gdpr:sweep-deletions')->hourly()->onOneServer()->withoutOverlapping();

// T-050 / ADR-010: analyze-then-delete. Hourly, not daily — the retention
// window is measured in hours, and a daily sweep would mean an original could
// outlive its 72h by most of another day. Deleting somebody else's video late
// is the one direction this policy must not err in.
Schedule::command('reelmap:media:prune-originals')->hourly()->onOneServer()->withoutOverlapping();

// T-050 / NFR-11: raw provider payloads have a 90-day window, so a daily pass
// is granular enough. Off-peak — it rewrites rows the ingest path writes to.
Schedule::command('reelmap:sources:prune-payloads')->dailyAt('04:10')->onOneServer()->withoutOverlapping();

// T-050: sweep finished data-export archives. Daily is well inside their
// multi-day retention, and each run is a directory listing plus a few unlinks.
Schedule::command('reelmap:gdpr:prune-exports')->dailyAt('04:30')->onOneServer()->withoutOverlapping();

// T-156: enforce the log window the privacy policy publishes; PruneLogFiles
// says why rotation alone does not.
//
// NEITHER onOneServer() NOR withoutOverlapping(), and the second is the subtle
// one: logs are per-machine FILES, so every box must sweep its own — but the
// overlap mutex is keyed on sha1(expression + command) in the SHARED cache
// store, with no host component, so it is `onOneServer()` wearing a different
// name. One box would take the lock and the rest would skip the run. The
// command is a glob plus unlinks and idempotent, so two overlapping runs on one
// box are harmless; a fleet where only one box prunes is not.
//
// Hourly, because a window is only as tight as the sweep that enforces it: a
// daily pass makes the published "14 days" mean up to 15.
Schedule::command('reelmap:logs:prune')
    ->hourly()
    // The command exits non-zero when a file it should have deleted survived —
    // a permission error that `File::delete()` swallows. Without this line that
    // exit code goes to /dev/null and the retention promise fails silently,
    // every hour, while every other signal stays green.
    ->onFailure(fn () => Log::error('logs.prune_failed_run', [
        'command' => 'reelmap:logs:prune',
    ]));

// T-156: `failed_jobs.exception` holds a full stack trace and `payload` holds
// the job's arguments — the same request data the 14-day window covers, in a
// table nothing prunes and `DELETE /me` never reaches. A window that is true of
// the files and false of the database is not a window.
//
// RetentionWindow rather than the config, because the two sinks INVERT its
// meaning at zero: Monolog reads `days=0` as keep-forever, artisan reads
// `--hours=0` as delete-everything-before-now. An empty `LOG_DAILY_DAYS=`
// casts to 0, so deriving this from the raw value would have left the files
// untouched and silently emptied the failed-job table — the record an incident
// is reconstructed from — while reporting success. The floor lives with the
// conversion so there is no second site to forget it at.
Schedule::command('queue:prune-failed', ['--hours' => RetentionWindow::hours()])
    // Hourly, matching the log sweep: a daily pass makes the published window
    // mean up to a day longer, and the two sinks state the same promise.
    // onOneServer() here, unlike the log sweep, because failed_jobs is one
    // shared table rather than a file on each box.
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// T-045 / 06 §4.3: the monthly payout run, first business day. One earner's
// failed KYC must never stop the others being paid — the command catches per
// user and continues, so this schedule is safe to leave unattended.
Schedule::command('reelmap:payouts:run')->monthlyOn(1, '09:00')->onOneServer()->withoutOverlapping();
