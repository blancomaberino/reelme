<?php

namespace App\Console\Commands;

use App\Jobs\Gdpr\PurgeUserData;
use App\Models\User;
use App\Services\Gdpr\AccountDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The fail-safe behind account deletion (T-050).
 *
 * `AccountDeletion::request()` queues one `PurgeUserData` delayed by two weeks,
 * and that job is the fast path — not the guarantee. Fourteen days is a long
 * time to trust a single row in Redis: a flush, a `horizon:clear`, a failed
 * deploy, or the job landing in `failed_jobs` all end the same way, with an
 * account that was promised erasure and silently never gets it. Nothing would
 * report that. It is not a backlog, it is a compliance breach that looks
 * exactly like success.
 *
 * So the database is the source of truth for what is owed, and this sweep asks
 * it directly. `PurgeUserData` is idempotent, re-checks the clock itself, and
 * is unique-until-processing, so a redundant dispatch alongside a healthy
 * delayed job costs nothing.
 *
 * This is also what gives `users.deletion_requested_at`'s index a reader.
 */
class SweepDueDeletions extends Command
{
    protected $signature = 'reelmap:gdpr:sweep-deletions {--dry-run : Report what is due without dispatching}';

    protected $description = 'Dispatch purges for accounts whose deletion grace period has lapsed (T-050)';

    public function handle(AccountDeletion $deletion): int
    {
        $due = User::onlyTrashed()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<=', now()->subDays((int) config('gdpr.purge_grace_days')))
            // Finished purges are excluded by `purged_at` — without it every
            // account ever erased matches this query on every hourly run and
            // gets a full re-purge, forever.
            //
            // The one exception is a purge that deliberately held the Stripe
            // linkage back while a payout was in flight: that account IS still
            // owed work, and something has to come back for it once the
            // transfer settles. The job is idempotent, so the revisit costs
            // exactly the one remaining field.
            ->where(fn ($q) => $q->whereNull('purged_at')
                ->orWhereNotNull('stripe_connect_account_id'))
            ->get(['id', 'deletion_requested_at', 'purged_at', 'stripe_connect_account_id']);

        $dispatched = 0;

        foreach ($due as $user) {
            // Re-checked per row rather than trusted from the query: the two
            // must not be able to disagree, and `isWithinGrace` is the same
            // clock the job itself will consult.
            if ($deletion->isWithinGrace($user)) {
                continue;
            }

            if (! $this->option('dry-run')) {
                PurgeUserData::dispatch($user->id);
            }

            $dispatched++;
        }

        if ($dispatched > 0) {
            // Worth a log line even on the happy path: a non-zero count here
            // means the delayed job did NOT do its job, and that is the signal
            // that something is wrong with the queue rather than with GDPR.
            Log::info('gdpr.sweep.dispatched', ['count' => $dispatched, 'dry_run' => (bool) $this->option('dry-run')]);
        }

        $this->info($this->option('dry-run')
            ? "{$dispatched} account(s) are due for purge."
            : "Dispatched {$dispatched} overdue purge(s).");

        return self::SUCCESS;
    }
}
