<?php

namespace App\Exceptions;

use Exception;

/**
 * A redemption could not be issued or honoured (T-043, 03 §3.4).
 *
 * Every case carries a machine-readable `details.reason` because the two
 * clients branch on it and cannot branch on prose: the diner app tells you
 * *why* you can't get a code (come back tomorrow vs. you already have one), and
 * the restaurant app distinguishes "this code is for another venue" from "this
 * code was already used" — which are a mistake and a possible fraud attempt
 * respectively, and read very differently to a person at a till.
 *
 * Mapped to the standard error envelope by {@see ApiExceptionRenderer}, exactly
 * like {@see ClaimException}.
 */
class RedemptionInvalid extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    // ---- Verify-side reasons (03 §3.4) -------------------------------------

    /**
     * No such code.
     *
     * Deliberately the same shape for "never existed" and "malformed": a till is
     * not a place to help someone enumerate valid codes.
     */
    public static function notFound(): self
    {
        return self::reason('not_found', 'That code is not valid.', 404);
    }

    public static function expired(): self
    {
        return self::reason('expired', 'That code has expired.');
    }

    /**
     * The code is real but belongs to another venue's offer — a diner at the
     * wrong restaurant, or a code passed between venues.
     */
    public static function wrongPlace(): self
    {
        return self::reason('wrong_place', 'That code is for a different restaurant.');
    }

    /** Already used, and not by this same verification (which replays instead). */
    public static function alreadyRedeemed(): self
    {
        return self::reason('already_redeemed', 'That code has already been used.');
    }

    /** Voided or expired — real, but not in a state that can be honoured. */
    public static function notLive(): self
    {
        return self::reason('not_live', 'That code is no longer valid.');
    }

    /** Staff device is outside the venue's geofence (06 §3). */
    public static function outsideGeofence(int $distanceM): self
    {
        return self::reason(
            'outside_geofence',
            'Verification must happen at the restaurant.',
            422,
            ['distance_m' => $distanceM],
        );
    }

    // ---- Issue-side reasons (06 §3 anti-fraud) -----------------------------

    /** The offer is not redeemable: paused, out of window, or sold out (T-042). */
    public static function offerNotRedeemable(): self
    {
        return self::reason('offer_not_redeemable', 'This offer is not available right now.');
    }

    /** The diner already holds a live code for this offer. */
    public static function alreadyIssued(): self
    {
        return self::reason('already_issued', 'You already have a code for this offer.', 409);
    }

    /** `quota_per_user` reached across the offer's lifetime. */
    public static function userQuotaReached(): self
    {
        return self::reason('user_quota_reached', 'You have already used this offer.');
    }

    /** Per-diner velocity: 3 issues/day, 10/week (06 §3). */
    public static function velocityExceeded(): self
    {
        return self::reason('velocity_exceeded', 'You have claimed too many offers recently. Try again later.', 429);
    }

    /** Same diner, same venue, inside the 7-day cooldown (06 §3). */
    public static function cooldown(): self
    {
        return self::reason('cooldown', 'You have recently redeemed at this restaurant. Try again in a few days.');
    }

    /** The diner operates the venue — 06 §3 blocks self-dealing outright. */
    public static function selfDealing(): self
    {
        return self::reason('self_dealing', 'You cannot redeem an offer at a restaurant you operate.', 403);
    }

    /** Staff account exceeded 30 verifies/hour (06 §3). */
    public static function staffVelocityExceeded(): self
    {
        return self::reason('staff_velocity_exceeded', 'Too many verifications from this account. Try again shortly.', 429);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function reason(string $reason, string $message, int $status = 422, array $extra = []): self
    {
        return new self(
            $status === 404 ? 'not_found' : 'redemption_invalid',
            $message,
            $status,
            ['reason' => $reason] + $extra,
        );
    }
}
