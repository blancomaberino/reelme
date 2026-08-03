<?php

namespace App\Enums;

/**
 * The chart of accounts (T-044, 02 §3.15, 06 §4.2).
 *
 * Six accounts, and the set is deliberately closed: a new one is a schema-level
 * decision about what the business owes whom, not something a caller invents at
 * a posting site. Per-party balances come from the `user_id` subledger and the
 * `reference` morph, NOT from account names — 06 §4.2 writes them as
 * `influencer_payable:{id}`, but encoding an id in a varchar makes every balance
 * query a string parse and every new party a new account.
 *
 * **Normal sides.** Which direction increases an account is the difference
 * between a €3 fee owed to the platform and a €3 refund owed to a restaurant,
 * so it is stated here once ({@see normalDirection()}) and every balance is
 * computed against it. Assets and expenses grow with debits; liabilities,
 * equity and revenue grow with credits.
 */
enum LedgerAccount: string
{
    /**
     * ASSET. What restaurants owe us for verified redemptions, until the
     * monthly invoice is paid (06 §2.3 bills monthly, never per redemption).
     */
    case RestaurantReceivable = 'restaurant_receivable';

    /** REVENUE. The platform's margin — what is left after the influencer share. */
    case PlatformRevenue = 'platform_revenue';

    /**
     * LIABILITY. What influencers have earned and we have not yet paid.
     *
     * `user_id` set = a claimed influencer's payable balance. `user_id` NULL =
     * ESCROW for an identity nobody has claimed yet (06 §5.3): the money is
     * owed, we just do not know to whom yet, and the redemption reference is
     * what ties it back to the influencer. One account rather than the
     * `payable` / `escrow` pair in 06 §4.2 — the distinction is exactly "do we
     * have a user", which the column already answers.
     */
    case InfluencerEarnings = 'influencer_earnings';

    /** EXPENSE. Restaurant-side fee expense — the mirror used by credit notes (06 §4.4). */
    case RestaurantFees = 'restaurant_fees';

    /** EXPENSE. Stripe's cut, absorbed at payout level in v1 (06 §4.1). */
    case StripeFees = 'stripe_fees';

    /**
     * LIABILITY. Money in flight: it has left an influencer's payable balance
     * and has not yet left our cash.
     *
     * CREDIT-normal, which reads backwards until you follow 06 §4.2's entries 4
     * and 5: the payout run *credits* this account when a transfer starts, and
     * *debits* it when the transfer is confirmed and platform cash actually
     * moves. So a positive balance here means "we owe this to transfers already
     * promised" — an obligation, not an asset we hold.
     */
    case PayoutClearing = 'payout_clearing';

    /**
     * The direction that INCREASES this account.
     *
     * Balances are `sum(normal side) - sum(opposite side)`, so a positive
     * balance always means "more of what this account is for" — a receivable
     * that is owed, earnings that are payable. Without this every caller would
     * have to remember the sign convention per account, and one that got it
     * backwards would report a debt as a credit.
     */
    public function normalDirection(): LedgerDirection
    {
        return match ($this) {
            self::RestaurantReceivable,
            self::RestaurantFees,
            self::StripeFees => LedgerDirection::Debit,

            self::PlatformRevenue,
            self::InfluencerEarnings,
            self::PayoutClearing => LedgerDirection::Credit,
        };
    }
}
