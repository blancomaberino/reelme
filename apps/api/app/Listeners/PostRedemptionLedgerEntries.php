<?php

namespace App\Listeners;

use App\Enums\LedgerAccount;
use App\Events\RedemptionVerified;
use App\Models\Influencer;
use App\Models\Redemption;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\RedemptionLedgerKeys;
use RuntimeException;

/**
 * Turns a verified redemption into money (T-044, 06 §4.1–4.2).
 *
 * **NOT `ShouldQueue`, and that is the single most important fact about this
 * class.** T-043 dispatches `RedemptionVerified` INSIDE its verify transaction
 * precisely so this runs there too: the fee commits with the status flip or
 * neither does. A queued listener runs after commit, and the two failure modes
 * it opens are the worst ones the business has — a redemption marked redeemed
 * with no fee (a free meal) or a fee posted for a redemption that rolled back
 * (money invented). The notification listener on this same event IS queued,
 * with `$afterCommit`; the opposite requirement, deliberately.
 *
 * The posting, at the default €3.00 with a 50% share:
 *
 *     debit  restaurant_receivable  300   ← what the venue owes on next invoice
 *     credit platform_revenue       150   ← our margin
 *     credit influencer_earnings    150   ← theirs (user_id null = escrow)
 *
 * One transaction rather than 06 §4.2's two events. The net position of every
 * account is identical; a single balanced set is simpler to reverse (06 §4.4)
 * and cannot half-post.
 */
class PostRedemptionLedgerEntries
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function handle(RedemptionVerified $event): void
    {
        $redemption = $event->redemption;

        // A replayed verify re-dispatches nothing (T-043 returns before the
        // dispatch), but a fee already posted is still checked here: this
        // listener is the last thing standing between a retry and a double
        // charge, and it must not depend on a caller upstream getting it right.
        $prefix = RedemptionLedgerKeys::capture($redemption);

        if ($this->ledger->findByPrefix($prefix) !== null) {
            return;
        }

        $fee = $this->fee();
        $currency = (string) config('monetization.currency');
        $shareBps = $this->shareBps($redemption);

        // intdiv, not round: the platform keeps the remainder cent. Rounding up
        // would let a 1-cent fee pay out 1 cent AND keep 1 cent, which does not
        // balance — and the split must be exact, because these two numbers are
        // the transaction's only credits.
        $influencerShare = intdiv($fee * $shareBps, 10_000);
        $platformShare = $fee - $influencerShare;

        $lines = [
            LedgerLine::debit(
                LedgerAccount::RestaurantReceivable,
                $fee,
                $currency,
                memo: 'Redemption fee owed by the venue',
            ),
        ];

        // A zero-value credit is not a line — the amount CHECK forbids it, and a
        // 100% share to either party is legal (06 §4.1 makes the split
        // configurable per offer).
        if ($platformShare > 0) {
            $lines[] = LedgerLine::credit(LedgerAccount::PlatformRevenue, $platformShare, $currency, memo: 'Platform margin');
        }

        if ($influencerShare > 0) {
            $lines[] = LedgerLine::credit(
                LedgerAccount::InfluencerEarnings,
                $influencerShare,
                $currency,
                // NULL when the influencer identity is unclaimed: the money is
                // owed, we just do not know to whom yet (06 §5.3). The
                // redemption reference is what ties escrow back to the identity.
                userId: $this->earnerUserId($redemption),
                memo: 'Influencer share',
            );
        }

        $this->ledger->record($prefix, $lines, $redemption, 'Redemption '.$redemption->id.' verified');

        // Priced at REDEMPTION, not at issue (06 §2.3): an offer repriced while
        // a code was in a diner's pocket must bill the rate in force when they
        // actually walked in. T-043 leaves both columns null for exactly this.
        $redemption->forceFill(['fee_amount' => $fee, 'currency' => $currency])->save();
    }

    /**
     * The offer's FROZEN share.
     *
     * Read from the offer, never from config: 06 §4.1 says the split is stored
     * on the offer at creation and changes are never retroactive. A campaign
     * renegotiated in March must not reprice February.
     */
    private function shareBps(Redemption $redemption): int
    {
        // Straight off the offer: `offer_id` is NOT NULL and RESTRICT-protected,
        // so the relation always resolves. The config default is what T-042 seeds
        // the COLUMN with, not a runtime fallback — a redemption whose offer
        // vanished would be a bug to surface, not to paper over.
        $bps = (int) $redemption->offer->influencer_share_bps;

        if ($bps < 0 || $bps > 10_000) {
            throw new RuntimeException("Influencer share must be 0–10000 bps; offer carries {$bps}.");
        }

        return $bps;
    }

    /**
     * The configured fee, checked against 06 §2.3's band.
     *
     * Asserted rather than trusted: a typo in an env var would otherwise bill
     * every restaurant wrongly and look exactly like normal operation.
     */
    private function fee(): int
    {
        $fee = (int) config('monetization.redemption_fee_minor');
        $min = (int) config('monetization.redemption_fee_min_minor');
        $max = (int) config('monetization.redemption_fee_max_minor');

        if ($fee < $min || $fee > $max) {
            throw new RuntimeException(
                "Redemption fee {$fee} is outside the configured band {$min}–{$max} (06 §2.3). Refusing to post."
            );
        }

        return $fee;
    }

    /**
     * Whose earnings these are — or null for escrow.
     *
     * The influencer's CLAIMING user, not the influencer id: `ledger_entries`
     * subledgers by user, because that is who eventually gets a Stripe transfer
     * (T-045). An unclaimed identity has no user, and the null is meaningful.
     */
    private function earnerUserId(Redemption $redemption): ?int
    {
        if ($redemption->attributed_influencer_id === null) {
            return null;
        }

        $influencer = Influencer::query()->find($redemption->attributed_influencer_id);

        return $influencer?->claimed_by_user_id;
    }
}
