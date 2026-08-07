<?php

namespace App\Jobs\Gdpr;

use App\Models\User;
use App\Services\Gdpr\AccountDeletion;
use App\Services\Gdpr\UserDataPurger;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Erase an account once its grace period has run out (T-050).
 *
 * Carries the user ID, not the model: this job sits on the queue for two weeks,
 * and a serialised model would be a fortnight-old snapshot of a row that has
 * very much changed by the time it runs. It re-reads and re-decides.
 *
 * Unique per user so a second deletion request cannot queue a second purge of
 * one account. Unique UNTIL PROCESSING, not until completion: the "check back
 * later" re-dispatch at the bottom of handle() carries the same key, and a lock
 * held for the duration of the job would silently swallow it.
 */
class PurgeUserData implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Safety net if a worker dies holding the lock — never block a purge forever. */
    public int $uniqueFor = 3600;

    /**
     * One try. A purge that fails mid-way has already committed whatever it
     * committed; the safe recovery is a human looking at the log, not an
     * automatic re-run racing whatever left it broken. It is idempotent, so a
     * manual re-dispatch is always available.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue((string) config('gdpr.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(UserDataPurger $purger, AccountDeletion $deletion): void
    {
        $user = User::withTrashed()->find($this->userId);

        // Signed back in during the grace period, so the row is live again.
        // The delayed job cannot be recalled from the queue — this check is
        // what makes cancellation real. `isPending`, not `trashed`: an admin
        // ban is also a soft delete, and a ban is not a request to be erased.
        if ($user === null || ! $deletion->isPending($user)) {
            Log::info('gdpr.purge.skipped', ['user_id' => $this->userId, 'reason' => 'not_pending_deletion']);

            return;
        }

        if ($deletion->isWithinGrace($user)) {
            // Deliberately NOT re-dispatched from here. A job that re-queues
            // itself is an infinite loop the moment the queue connection is
            // `sync` (which the test env is, and which a dev box easily can
            // be) — it would run, find itself early, dispatch, run again.
            //
            // Losing this job is still not acceptable, so the guarantee lives
            // one level up instead: `reelmap:gdpr:sweep-deletions` runs hourly
            // and asks the database what is actually owed. Reconciliation
            // belongs in a loop that can see all of it, not in each job's
            // opinion of itself.
            Log::info('gdpr.purge.skipped', ['user_id' => $this->userId, 'reason' => 'still_within_grace']);

            return;
        }

        $unsettled = $purger->hasUnsettledPayout($user);

        $purger->purge($user);

        // Everything personal is gone either way; only the Stripe linkage was
        // held back so money already in flight has somewhere to land. The
        // hourly sweep comes back for it once the payout settles — again not a
        // self-dispatch, which under a `sync` connection would recurse until
        // the stack gave out.
        if ($unsettled) {
            Log::info('gdpr.purge.deferred_financial_linkage', ['user_id' => $this->userId]);
        }
    }
}
