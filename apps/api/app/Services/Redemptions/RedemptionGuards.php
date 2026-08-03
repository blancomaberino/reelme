<?php

namespace App\Services\Redemptions;

use App\Enums\RedemptionStatus;
use App\Exceptions\RedemptionInvalid;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The 06 §3 anti-fraud table, in one place (T-043).
 *
 * They are gathered here rather than spread through the issuer because they are
 * a POLICY, and a policy that lives at six call sites is six policies. Each
 * method is one row of that table and throws its own
 * {@see RedemptionInvalid} reason, so a client can tell "come back tomorrow"
 * from "you already have a code" — materially different instructions.
 *
 * What is NOT here: the "one live code per (offer, user)" rule. That one is a
 * partial unique index in Postgres, because two concurrent issue requests both
 * pass any check written in PHP. The issuer catches the constraint violation
 * instead — see {@see RedemptionIssuer}.
 */
class RedemptionGuards
{
    /** 06 §3: per diner, max 3 issues/day and 10/week. */
    public const MAX_ISSUES_PER_DAY = 3;

    public const MAX_ISSUES_PER_WEEK = 10;

    /** 06 §3: a diner may not redeem twice at the same venue inside 7 days. */
    public const COOLDOWN_DAYS = 7;

    /** 06 §3: a verifying staff account is capped at 30 verifies/hour. */
    public const MAX_VERIFIES_PER_HOUR = 30;

    /**
     * Everything that must hold before a code is handed out.
     *
     * Ordered cheapest-and-most-informative first: an offer that is simply
     * paused should say so, rather than the diner being told they are rate
     * limited because the paused check happened to run second.
     *
     * @throws RedemptionInvalid
     */
    public function assertMayIssue(Offer $offer, Place $place, User $diner): void
    {
        $this->assertOfferRedeemable($offer);
        $this->assertNotSelfDealing($place, $diner);
        $this->assertUserQuota($offer, $diner);
        $this->assertNoCooldown($place, $diner);
        $this->assertIssueVelocity($diner);
    }

    /**
     * The offer itself must be live — delegated to T-042's single gate so this
     * class never grows a second, subtly different definition of redeemable.
     * `quota_per_day` needs today's rows, which only exist now, so this is the
     * caller that finally supplies them.
     */
    public function assertOfferRedeemable(Offer $offer): void
    {
        $issuedToday = Redemption::query()
            ->where('offer_id', $offer->id)
            ->whereDate('issued_at', now()->toDateString())
            // A voided or expired code returns its slot; counting one would let
            // a run of cancellations retire an offer for the rest of the day.
            ->whereIn('status', RedemptionStatus::holdingQuota())
            ->count();

        if (! $offer->isRedeemable($issuedToday)) {
            throw RedemptionInvalid::offerNotRedeemable();
        }
    }

    /**
     * 06 §3 self-dealing: the operator of a venue cannot redeem its own offers.
     *
     * Blocked outright rather than flagged, because it is not ambiguous — every
     * such redemption is a restaurant billing itself a fee to move money to an
     * influencer, and there is no honest version of it.
     */
    public function assertNotSelfDealing(Place $place, User $diner): void
    {
        if ($diner->ownsPlace($place)) {
            throw RedemptionInvalid::selfDealing();
        }
    }

    /**
     * `quota_per_user` (02 §3.13) — over the offer's LIFETIME, not per day.
     *
     * Counts codes that hold a slot: an expired or voided one gives it back, so
     * a diner whose code lapsed unused is not locked out of an offer they never
     * actually used.
     */
    public function assertUserQuota(Offer $offer, User $diner): void
    {
        $held = Redemption::query()
            ->where('offer_id', $offer->id)
            ->where('user_id', $diner->id)
            ->whereIn('status', RedemptionStatus::holdingQuota())
            ->count();

        if ($held >= $offer->quota_per_user) {
            throw RedemptionInvalid::userQuotaReached();
        }
    }

    /**
     * 06 §3 cooldown: one REDEEMED visit per diner per venue per 7 days.
     *
     * Keyed on the place, not the offer — otherwise a venue running three
     * offers would let the same diner redeem three times a week, which is the
     * pattern the rule exists to stop.
     */
    public function assertNoCooldown(Place $place, User $diner): void
    {
        $recent = Redemption::query()
            ->where('user_id', $diner->id)
            ->where('status', RedemptionStatus::Redeemed)
            ->where('redeemed_at', '>=', now()->subDays(self::COOLDOWN_DAYS))
            ->whereIn('offer_id', DB::table('offers')->select('id')->where('place_id', $place->id))
            ->exists();

        if ($recent) {
            throw RedemptionInvalid::cooldown();
        }
    }

    /**
     * 06 §3 per-diner velocity, across ALL offers.
     *
     * Uses the cache-backed RateLimiter rather than counting rows: the limit is
     * about request behaviour, and a diner who is refused should not also get
     * their refusal recorded as a redemption. `attempt`-free — we consume only
     * after every other check has passed (see {@see recordIssue()}), so a
     * blocked self-dealer does not burn the day's budget of an honest one who
     * shares their device.
     */
    public function assertIssueVelocity(User $diner): void
    {
        if (RateLimiter::tooManyAttempts($this->dayKey($diner), self::MAX_ISSUES_PER_DAY)
            || RateLimiter::tooManyAttempts($this->weekKey($diner), self::MAX_ISSUES_PER_WEEK)) {
            throw RedemptionInvalid::velocityExceeded();
        }
    }

    /** Consume the diner's velocity budget — only once a code was actually issued. */
    public function recordIssue(User $diner): void
    {
        RateLimiter::hit($this->dayKey($diner), (int) now()->diffInSeconds(now()->addDay()));
        RateLimiter::hit($this->weekKey($diner), (int) now()->diffInSeconds(now()->addWeek()));
    }

    /**
     * 06 §3: 30 verifies/hour per staff account, an alert threshold for admins.
     *
     * Consumed BEFORE the verification runs, unlike the issue side: here the
     * attempt itself is the thing being limited, because a staff account
     * grinding through guessed codes is exactly the abuse case.
     */
    public function assertVerifyVelocity(User $staff): void
    {
        $key = 'redemption:verify:'.$staff->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_VERIFIES_PER_HOUR)) {
            throw RedemptionInvalid::staffVelocityExceeded();
        }

        RateLimiter::hit($key, (int) now()->diffInSeconds(now()->addHour()));
    }

    private function dayKey(User $diner): string
    {
        return 'redemption:issue:day:'.$diner->id;
    }

    private function weekKey(User $diner): string
    {
        return 'redemption:issue:week:'.$diner->id;
    }
}
