<?php

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

// T-045 / 06 §4.3: the monthly payout run, first business day. One earner's
// failed KYC must never stop the others being paid — the command catches per
// user and continues, so this schedule is safe to leave unattended.
Schedule::command('reelmap:payouts:run')->monthlyOn(1, '09:00')->onOneServer()->withoutOverlapping();
