<?php

use App\Enums\OfferStatus;
use App\Models\Offer;

/**
 * `Offer::isRedeemable()` — the single gate T-043 will issue against (T-042).
 *
 * The organising property: **`status` alone is never the answer.** Nothing
 * rewrites the column when a window lapses or a quota fills, so an offer can sit
 * at `status = active` while being un-redeemable for three independent reasons.
 * Each of them gets its own exhaustion case here, because a redemption issued
 * against any one of them is a fee charged to a restaurant that never agreed to
 * it.
 */
it('is redeemable when active, in-window, and under every quota', function () {
    $offer = Offer::factory()->active()->make(['quota_total' => 10, 'quota_per_day' => 5]);

    expect($offer->isRedeemable(issuedToday: 2))->toBeTrue();
});

describe('the validity window', function () {
    it('is not redeemable once ends_at has passed, even though status still reads active', function () {
        $offer = Offer::factory()->expired()->make();

        expect($offer->status)->toBe(OfferStatus::Active)
            ->and($offer->isWithinWindow())->toBeFalse()
            ->and($offer->isRedeemable())->toBeFalse();
    });

    it('is not redeemable before starts_at', function () {
        $offer = Offer::factory()->upcoming()->make();

        expect($offer->isRedeemable())->toBeFalse();
    });

    it('treats a null ends_at as open-ended rather than as already ended', function () {
        $offer = Offer::factory()->active()->make(['ends_at' => null]);

        expect($offer->isRedeemable())->toBeTrue();
    });

    it('is not redeemable while paused or in draft', function (OfferStatus $status) {
        $offer = Offer::factory()->active()->make(['status' => $status]);

        expect($offer->isRedeemable())->toBeFalse();
    })->with([OfferStatus::Draft, OfferStatus::Paused, OfferStatus::Archived]);
});

describe('the lifetime quota', function () {
    it('is not redeemable once redemptions_count reaches quota_total', function () {
        $offer = Offer::factory()->quotaExhausted(10)->make();

        expect($offer->hasTotalQuotaLeft())->toBeFalse()
            ->and($offer->isRedeemable())->toBeFalse()
            ->and($offer->remainingQuota())->toBe(0);
    });

    it('is still redeemable on the last remaining slot', function () {
        $offer = Offer::factory()->active()->make(['quota_total' => 10, 'redemptions_count' => 9]);

        expect($offer->isRedeemable())->toBeTrue()
            ->and($offer->remainingQuota())->toBe(1);
    });

    it('reports an unlimited quota as null, not as a number', function () {
        $offer = Offer::factory()->active()->make(['quota_total' => null, 'redemptions_count' => 9999]);

        expect($offer->remainingQuota())->toBeNull()
            ->and($offer->isRedeemable())->toBeTrue();
    });

    /*
     * A counter cache that ran past its cap (a void that failed to decrement, a
     * quota lowered after the fact) must still read as exhausted rather than
     * wrapping to a negative "remaining".
     */
    it('never reports a negative remaining quota when the counter overshot the cap', function () {
        $offer = Offer::factory()->active()->make(['quota_total' => 5, 'redemptions_count' => 8]);

        expect($offer->remainingQuota())->toBe(0)
            ->and($offer->isRedeemable())->toBeFalse();
    });
});

describe('the per-day quota', function () {
    it('is not redeemable once today has hit quota_per_day', function () {
        $offer = Offer::factory()->active()->make(['quota_per_day' => 3]);

        expect($offer->hasDailyQuotaLeft(3))->toBeFalse()
            ->and($offer->isRedeemable(issuedToday: 3))->toBeFalse()
            // The lifetime cap is untouched — this is a throttle, not a budget.
            ->and($offer->hasTotalQuotaLeft())->toBeTrue();
    });

    it('is redeemable again below the daily cap', function () {
        $offer = Offer::factory()->active()->make(['quota_per_day' => 3]);

        expect($offer->isRedeemable(issuedToday: 2))->toBeTrue();
    });

    it('ignores today entirely when quota_per_day is null', function () {
        $offer = Offer::factory()->active()->make(['quota_per_day' => null]);

        expect($offer->isRedeemable(issuedToday: 9999))->toBeTrue();
    });
});
