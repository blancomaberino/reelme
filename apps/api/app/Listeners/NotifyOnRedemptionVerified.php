<?php

namespace App\Listeners;

use App\Events\RedemptionVerified;
use App\Notifications\RedemptionConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Tell the diner their code was accepted (T-043, 06 §3).
 *
 * QUEUED, unlike the ledger listener T-044 will add to the same event — and the
 * difference is deliberate. A queued listener runs AFTER the verify transaction
 * commits, which is exactly wrong for money (a fee posted for a redemption that
 * rolled back is invented) and exactly right for a push (a notification sent for
 * a redemption that rolled back is a lie you cannot recall).
 *
 * `ShouldQueue` also keeps Expo's HTTP call off the request path: a restaurant
 * waiting on a push delivery before the till says "redeemed" is a restaurant
 * that thinks the app is broken.
 */
class NotifyOnRedemptionVerified implements ShouldQueue
{
    /**
     * Do not queue this until the verify transaction COMMITS.
     *
     * The event is dispatched inside that transaction (deliberately — T-044's
     * ledger listener must be atomic with the state flip), and the queue
     * connection is Redis with `after_commit` off, so without this the job is
     * pushed the instant the event fires. A transaction that then rolls back
     * leaves the job standing, and the diner is told "your offer was redeemed"
     * for a redemption that never happened — a message that cannot be recalled.
     */
    public bool $afterCommit = true;

    public function handle(RedemptionVerified $event): void
    {
        $diner = $event->redemption->user;

        if ($diner === null) {
            return;
        }

        Notification::send($diner, new RedemptionConfirmed($event->redemption));
    }
}
