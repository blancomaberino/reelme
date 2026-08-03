<?php

namespace App\Enums;

/**
 * Lifecycle of a payout to an influencer (T-045, 02 §3.16, 06 §4.3).
 *
 * `pending` → `processing` → `paid` | `failed` | `reversed`.
 *
 * The ledger hold is taken at `pending` — the moment a payout is REQUESTED, the
 * money leaves the influencer's available balance. That is what stops a second
 * request from spending the same euros while the first is in flight with
 * Stripe, and it is why `failed` has to release the hold rather than simply
 * record a failure.
 *
 * Stripe webhooks arrive out of order and are redelivered, so the transitions
 * are checked rather than assumed: a `paid` event for an already-`failed`
 * payout is logged for an admin, never applied.
 */
enum PayoutStatus: string
{
    /** Requested; the ledger hold is taken and no transfer has been created. */
    case Pending = 'pending';

    /** A Stripe Transfer exists and we are waiting on its outcome. */
    case Processing = 'processing';

    /** Money reached the connected account. Terminal. */
    case Paid = 'paid';

    /** Stripe refused or the transfer failed — the hold is released. Terminal. */
    case Failed = 'failed';

    /** Paid, then clawed back by Stripe. Terminal. */
    case Reversed = 'reversed';

    /** Has this payout reached a state nothing should move it out of? */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Reversed], true);
    }

    /** Is the money still committed — i.e. does the ledger hold still stand? */
    public function holdsFunds(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Paid], true);
    }
}
