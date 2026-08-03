<?php

namespace App\Services\Redemptions;

use App\Enums\RedemptionStatus;
use App\Exceptions\RedemptionInvalid;
use App\Models\Offer;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

        $this->guards->assertMayIssue($offer, $place, $diner);

        $attribution = $this->attribution->resolve($offer, $referralShareId);

        $redemption = $this->insert($offer, $diner, $attribution);

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
    private function insert(Offer $offer, User $diner, array $attribution): Redemption
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = RedemptionCode::generate();

            try {
                return DB::transaction(function () use ($offer, $diner, $attribution, $code): Redemption {
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
