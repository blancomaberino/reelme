<?php

namespace App\Services\Redemptions;

use App\Enums\RedemptionStatus;
use App\Models\Offer;
use App\Services\Payments\PayoutService;
use Illuminate\Support\Facades\Log;

/**
 * The one writer of `offers.redemptions_count` (T-127, 06 §2.2).
 *
 * The column is a counter cache over the redemptions that HOLD a slot —
 * {@see RedemptionStatus::holdingQuota()}, i.e. `issued` + `redeemed`.
 * `expired` and `void` give theirs back, otherwise a run of abandoned codes
 * would silently retire an offer the restaurant is still paying to run.
 *
 * FOUR status transitions move a redemption relative to that set, and only three
 * of them are writes here: issue (+1, {@see RedemptionIssuer}), verify
 * `issued → redeemed` (delta 0 — both states hold a slot, so
 * {@see RedemptionVerifier::verify()} deliberately calls nothing), void (−1,
 * `RedemptionVoider`) and expire (−N, `ExpireRedemptions`). The zero-delta one is
 * the fragile one: narrow `holdingQuota()` and every query follows it
 * automatically while `claim()` keeps adding +1, so the writers and the
 * reconciler would disagree permanently and nothing would report it.
 *
 * Every mutation of it goes through here rather than through an `increment()`
 * scattered across the issuer, the voider and the expiry command, because the
 * two rules below have to hold identically at each of those sites and a fourth
 * caller will be added by whoever wires the voider to an admin action:
 *
 * 1. **The increment is the claim, not just the write.** It carries the lifetime
 *    cap in its own WHERE clause and reports whether it won, matching
 *    {@see PayoutService::markPaid()} and
 *    {@see RedemptionVerifier::verify()}. A caller that has already read the
 *    quota under a row lock should never see a refusal — which is exactly why
 *    it is checked: the guard costs one predicate and is the only thing that
 *    stands between a venue and an unbounded number of free desserts if a
 *    future caller ever forgets the lock.
 * 2. **A release returns every slot or none of them.** The decrement carries
 *    `redemptions_count >= $slots` in its own WHERE clause: not a floor that
 *    clamps at zero, but an all-or-nothing write that REFUSES when the counter
 *    cannot cover what is being handed back. Refusal is the deliberate choice of
 *    which way to be wrong — it leaves the counter reading HIGH, so the offer
 *    looks more sold out than it is and the venue loses exposure, where clamping
 *    to zero would leave it reading LOW and let the offer be over-redeemed,
 *    which costs the venue money and is the exact failure this class exists to
 *    prevent. The refusal is logged rather than swallowed, because the
 *    reconciliation command is the repair and a silent clamp is how it would
 *    never get run.
 * 3. **Neither write touches `offers.updated_at`.** The counter is derived from
 *    the `redemptions` rows, so moving it is bookkeeping, not an edit of the
 *    offer — and `updated_at` is the operator's own last-touched stamp, which is
 *    why the reconciliation command's repair pins it too. One rule for the
 *    column, stated once, rather than a diner's redemption and a nightly
 *    correction disagreeing about what counts as changing an offer.
 *
 * The repo's other joined counter, `places.shares_count`, is kept by
 * PlaceMerger under the opposite rule — always recount, never adjust — so the
 * divergence is worth stating: a merge rewrites which rows belong to a place at
 * all, and incremental math over that is simply wrong, whereas a redemption is
 * one row entering or leaving the holding set. More to the point, the issuer is
 * already holding the offer's row lock and needs a DECISION in the same
 * statement — claimed or refused — which a recount cannot give it. The recount
 * still exists; it runs out of band, as the reconciliation command.
 *
 * The two seams a reader reaches for first are both closed. A **model observer /
 * Eloquent event** is not merely costlier, it is UNAVAILABLE: every transition
 * above is a guarded query-builder `update()` — chosen because a model setter
 * cannot win a race against a second scan of the same QR — and query-builder
 * updates fire no Eloquent events, so an observer would mean converting all
 * three writers back to model saves and giving up exactly that race protection.
 * A **database trigger** would survive even a hand-written bulk UPDATE, but
 * `claim()` must return a business decision — claimed, or refused as a 422 —
 * inside the statement the issuer already holds a lock for, and a trigger can
 * only signal that as an SQL exception.
 */
class OfferQuotaCounter
{
    /**
     * Take one slot against the offer's lifetime cap.
     *
     * @return bool false when `quota_total` is already spent — the caller must
     *              not proceed with the redemption
     */
    public function claim(int $offerId): bool
    {
        $claimed = Offer::query()
            ->whereKey($offerId)
            // The very predicate the browse badge filters on, evaluated here as
            // the write's own precondition. Spelled once, on the model, so a
            // venue can never be advertised as having room this then refuses.
            ->notSoldOut()
            // `toBase()`, so Eloquent does not add `updated_at` — see the class
            // docblock's third rule.
            ->toBase()
            ->increment('redemptions_count');

        return $claimed === 1;
    }

    /**
     * Give slots back — a code expired unused, or a redemption was voided.
     *
     * @param  int  $slots  how many rows left the holding set; the expiry sweep
     *                      releases a whole chunk of an offer's codes at once
     */
    public function release(int $offerId, int $slots = 1): void
    {
        if ($slots < 1) {
            return;
        }

        $released = Offer::query()
            ->whereKey($offerId)
            ->where('redemptions_count', '>=', $slots)
            ->toBase()
            ->decrement('redemptions_count', $slots);

        if ($released === 1) {
            return;
        }

        // Reaching here means the cache disagrees with the rows: either the
        // offer vanished (impossible — `redemptions.offer_id` is
        // RESTRICT-on-delete) or the counter is already below what we are
        // returning. Neither is fixable from inside a single release, so it is
        // reported for the reconciler rather than clamped away.
        Log::warning('offer.quota_counter_drift', [
            // Which detector saw it. The reconciler reports the same drift under
            // this message with its own key set, and an alert rule should be
            // able to match both shapes without knowing either.
            'source' => 'release',
            'offer_id' => $offerId,
            'slots_to_release' => $slots,
            'reason' => 'counter below the number of slots being returned',
        ]);
    }
}
