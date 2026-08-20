<?php

namespace App\Services\Redemptions;

use App\Enums\RedemptionStatus;
use App\Exceptions\RedemptionInvalid;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands a diner a single-use code for an offer (T-043, 06 §3).
 *
 * The order here is load-bearing. Every anti-fraud rule that CAN be checked in
 * PHP is checked first, so the diner gets a specific reason; then the insert
 * runs, and the database has the final word on the one rule PHP cannot win —
 * "one live code per (offer, user)". Two concurrent requests both pass
 * {@see RedemptionGuards}, both reach the insert, and the partial unique index
 * rejects exactly one. Catching that violation and reporting it as
 * `already_issued` is what turns a race into a correct answer rather than a 500.
 */
class RedemptionIssuer
{
    /** 06 §3: a code is good for 24 hours. */
    public const TTL_HOURS = 24;

    /**
     * A collision on a 50-bit random code is vanishingly unlikely; retrying a
     * handful of times costs nothing and removes the failure mode entirely.
     */
    private const CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly RedemptionGuards $guards,
        private readonly RedemptionAttribution $attribution,
        private readonly OfferQuotaCounter $quota,
    ) {}

    /**
     * @param  int|null  $referralShareId  the share the diner navigated from, if any
     *
     * @throws RedemptionInvalid
     */
    public function issue(Offer $offer, User $diner, ?int $referralShareId = null): Redemption
    {
        $place = $offer->place;

        if ($place === null || ! $place->isPubliclyVisible()) {
            // An offer on a hidden or merged venue is not something a diner can
            // walk into, whatever the offer's own state says.
            throw RedemptionInvalid::offerNotRedeemable();
        }

        // Resolved BEFORE the transaction: attribution reads several tables and
        // has no bearing on the quota arithmetic, so it must not be holding the
        // offer's row lock while it does.
        $attribution = $this->attribution->resolve($offer, $referralShareId);

        $redemption = $this->insert($offer, $place, $diner, $attribution);

        // Only now — a diner refused for any reason above must not have spent
        // part of their daily allowance on the refusal.
        $this->guards->recordIssue($diner);

        return $redemption;
    }

    /**
     * @param  array{influencer_id: int|null, share_id: int|null}  $attribution
     *
     * @throws RedemptionInvalid
     */
    private function insert(Offer $offer, Place $place, User $diner, array $attribution): Redemption
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = RedemptionCode::generate();

            try {
                return DB::transaction(function () use ($offer, $place, $diner, $attribution, $code): Redemption {
                    /*
                     * Lock the OFFER row before counting anything against it.
                     *
                     * The quota checks read `redemptions_count` and today's rows
                     * and then insert. Without this lock two requests from
                     * DIFFERENT diners both read the same count and both insert,
                     * so `quota_total` and `quota_per_day` can be overshot by the
                     * number of requests in flight — a real cost to a venue that
                     * capped an offer at ten a day. The partial unique index only
                     * covers one diner racing themselves.
                     *
                     * Serialising issuance per offer is cheap: issues are
                     * human-paced and already throttled to 10/min per user.
                     */
                    $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->first();

                    if ($locked === null) {
                        throw RedemptionInvalid::offerNotRedeemable();
                    }

                    $this->guards->assertMayIssue($locked, $place, $diner);

                    // Belt and braces, not a second copy of the lock's job. The
                    // lock stops two issues interleaving their read and their
                    // write; the claim additionally carries `quota_total` in its
                    // own UPDATE's WHERE clause, so the lifetime cap still holds
                    // for a future caller that reaches the counter without the
                    // lock. It sits inside the transaction so a code collision or
                    // the anti-fraud unique index carries the slot back out.
                    if (! $this->quota->claim($offer->id)) {
                        // Under the lock this is impossible, so it is the single
                        // signal that the lock's guarantee has been lost — and
                        // the exception below is byte-identical to the 422 an
                        // ordinary sold-out offer returns. Without this line the
                        // broken invariant is indistinguishable, in every log and
                        // every metric, from a popular promotion.
                        Log::warning('offer.quota_claim_refused_under_lock', [
                            'offer_id' => $locked->id,
                            'redemptions_count' => $locked->redemptions_count,
                            'quota_total' => $locked->quota_total,
                        ]);

                        throw RedemptionInvalid::offerNotRedeemable();
                    }

                    $redemption = new Redemption;
                    $redemption->forceFill([
                        'offer_id' => $offer->id,
                        'user_id' => $diner->id,
                        'code' => $code,
                        // Placeholder: the signature covers the row id, which
                        // does not exist until the insert lands. Rewritten
                        // immediately below, inside the same transaction, so no
                        // reader ever observes the placeholder.
                        'qr_payload' => 'pending',
                        'status' => RedemptionStatus::Issued,
                        'issued_at' => now(),
                        'expires_at' => now()->addHours(self::TTL_HOURS),
                        'attributed_influencer_id' => $attribution['influencer_id'],
                        'attributed_share_id' => $attribution['share_id'],
                    ])->save();

                    $redemption->forceFill([
                        'qr_payload' => RedemptionQr::sign($code, (int) $redemption->id),
                    ])->save();

                    return $redemption;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Two different unique indexes can fire here and they mean
                // opposite things: a duplicated CODE is our own bad luck and
                // should be retried silently, while the partial unique on
                // (offer_id, user_id) is the anti-fraud rule doing its job and
                // must reach the diner as `already_issued`.
                if ($this->isDuplicateCode($e)) {
                    continue;
                }

                throw RedemptionInvalid::alreadyIssued();
            }
        }

        // Five collisions in a row is not luck — it means the generator is
        // broken. Failing loudly beats handing out a code we cannot trust.
        throw RedemptionInvalid::offerNotRedeemable();
    }

    private function isDuplicateCode(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'redemptions_code_unique');
    }
}
