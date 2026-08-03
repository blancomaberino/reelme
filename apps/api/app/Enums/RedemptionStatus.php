<?php

namespace App\Enums;

/**
 * Lifecycle of an issued redemption (T-043, 02 §3.14).
 *
 * `issued` → `redeemed` is the only transition that costs anyone money: 06 §2.3
 * makes **only `redeemed` billable**, so an abandoned code must reach `expired`
 * rather than linger as `issued` and look like an unbilled visit forever.
 *
 * The set is deliberately small and terminal-heavy. Three of the four states are
 * final — once a code is redeemed, expired or voided, nothing moves it again,
 * because a ledger entry (T-044) will have been written against that fact and a
 * state that can flip back is a fee that can be charged twice.
 */
enum RedemptionStatus: string
{
    /** Live: the diner holds a code the restaurant will honour. */
    case Issued = 'issued';

    /** Verified at the till. The billable event (06 §2.3). */
    case Redeemed = 'redeemed';

    /** The 24h window closed with no visit. Never billable. */
    case Expired = 'expired';

    /** Cancelled — a dispute, a moderation action, a refund (06 §4.4). */
    case Void = 'void';

    /**
     * Does this state still hold a slot against the offer's quota?
     *
     * `issued` and `redeemed` do; `expired` and `void` return theirs, which is
     * what keeps `offers.redemptions_count` from silently retiring an offer the
     * restaurant is still paying to run.
     */
    public function holdsQuota(): bool
    {
        return in_array($this, self::holdingQuota(), true);
    }

    /**
     * The states that hold a slot, as a list for query builders.
     *
     * Every quota question — the offer's per-day cap, the diner's per-user cap,
     * the counter cache — asks the same one, so it is spelled once. Three
     * hand-written `whereIn([Issued, Redeemed])` clauses is three places to
     * forget `void` when refunds land (06 §4.4).
     *
     * @return list<self>
     */
    public static function holdingQuota(): array
    {
        return [self::Issued, self::Redeemed];
    }
}
