<?php

namespace App\Events;

use App\Models\Redemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A redemption was honoured at the till (T-043).
 *
 * The seam T-044 hangs the double-entry ledger on. Dispatched INSIDE the verify
 * transaction so a listener that writes ledger entries commits with the state
 * flip or not at all — a fee posted against a redemption that rolled back is
 * money invented from nothing, and one that rolled back without its fee is a
 * free meal.
 *
 * Which means listeners on this event must NOT be queued for the ledger path:
 * a queued listener runs after commit and loses that atomicity. Notifications
 * (which are queued, and which nobody reconciles) are fine either way.
 */
class RedemptionVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Redemption $redemption) {}
}
