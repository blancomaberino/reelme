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
    public function handle(RedemptionVerified $event): void
    {
        $diner = $event->redemption->user;

        if ($diner === null) {
            return;
        }

        Notification::send($diner, new RedemptionConfirmed($event->redemption));
    }
}
