<?php

use App\Enums\PlaceStatus;
use App\Enums\RedemptionStatus;
use App\Exceptions\RedemptionInvalid;
use App\Models\Influencer;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\PlaceSource;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Redemptions\RedemptionCode;
use App\Services\Redemptions\RedemptionGuards;
use App\Services\Redemptions\RedemptionIssuer;
use App\Services\Redemptions\RedemptionQr;
use Illuminate\Support\Facades\DB;

/**
 * Issuing a code (T-043, 06 §3).
 *
 * Two organising properties, and every test belongs to one of them:
 *
 * 1. **Attribution is decided once, at issue, and never recomputed** (02 §5).
 *    The share that sent the diner here can be edited, re-analysed or deleted
 *    before they walk in; who earns from the visit was settled when the code
 *    was handed out. A payout that moves retroactively is one nobody can
 *    reconcile.
 * 2. **Every row of 06 §3's anti-fraud table has its own refusal reason.** A
 *    diner told "try again later" when they actually already hold a code will
 *    keep trying; the reason is the instruction.
 */
function issuer(): RedemptionIssuer
{
    return app(RedemptionIssuer::class);
}

function activeOfferAt(Place $place, array $attributes = []): Offer
{
    return Offer::factory()->active()->create(['place_id' => $place->id] + $attributes);
}

describe('the happy path', function () {
    it('issues a live code with a signed QR and a 24h window', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place);
        $diner = User::factory()->create();

        $redemption = issuer()->issue($offer, $diner);

        expect($redemption->status)->toBe(RedemptionStatus::Issued)
            ->and($redemption->user_id)->toBe($diner->id)
            ->and(RedemptionCode::isWellFormed($redemption->code))->toBeTrue()
            // ~24h, allowing for the seconds the test itself takes.
            ->and($redemption->expires_at->diffInHours($redemption->issued_at, true))
            ->toEqualWithDelta(RedemptionIssuer::TTL_HOURS, 0.01);

        // The QR is signed over the code AND the row id, so a payload lifted
        // from one redemption cannot be replayed against another.
        expect(RedemptionQr::verify($redemption->qr_payload, $redemption->code, (int) $redemption->id))->toBeTrue()
            ->and(RedemptionQr::verify($redemption->qr_payload, $redemption->code, (int) $redemption->id + 1))->toBeFalse();
    });

    /*
     * Distinct diners rather than one repeating: a single diner is capped at
     * three issues a day by 06 §3, so looping one would be testing the velocity
     * limit instead of the code space.
     */
    it('gives every diner a distinct code', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_day' => null]);

        $codes = collect(range(1, 10))->map(
            fn () => issuer()->issue($offer, User::factory()->create())->code,
        );

        expect($codes->unique())->toHaveCount(10);
        $codes->each(fn (string $code) => expect(RedemptionCode::isWellFormed($code))->toBeTrue());
    });
});

describe('attribution, frozen at issue', function () {
    /** A place whose primary source came from `$influencer` via `$share`. */
    function attributedPlace(): array
    {
        $place = Place::factory()->active()->create();
        $influencer = Influencer::factory()->create();
        $share = Share::factory()->create();
        $post = SourcePost::factory()->create(['influencer_id' => $influencer->id]);
        PlaceSource::factory()->create([
            'place_id' => $place->id,
            'share_id' => $share->id,
            'source_post_id' => $post->id,
            'is_primary' => true,
        ]);

        return [$place, $influencer, $share];
    }

    it('credits the share the diner navigated from', function () {
        [$place, $influencer, $share] = attributedPlace();
        $offer = activeOfferAt($place);

        $redemption = issuer()->issue($offer, User::factory()->create(), $share->id);

        expect($redemption->attributed_share_id)->toBe($share->id)
            ->and($redemption->attributed_influencer_id)->toBe($influencer->id)
            // Reachable as relations too — this is the pair T-044's payout and
            // T-048's dashboard read from.
            ->and($redemption->attributedInfluencer->id)->toBe($influencer->id)
            ->and($redemption->attributedShare->id)->toBe($share->id);
    });

    it('falls back to the primary source when the diner arrived with no referral', function () {
        [$place, $influencer, $share] = attributedPlace();

        $redemption = issuer()->issue(activeOfferAt($place), User::factory()->create());

        expect($redemption->attributed_share_id)->toBe($share->id)
            ->and($redemption->attributed_influencer_id)->toBe($influencer->id);
    });

    /*
     * The share id comes from the CLIENT. Without this check a diner could name
     * any share — including one of their own — and redirect the influencer's
     * earnings to themselves. It is dropped to the fallback rather than
     * rejected: the diner is not necessarily at fault, and refusing the whole
     * code over an attribution detail is a worse product.
     */
    it('ignores a share that has nothing to do with this venue', function () {
        [$place, $influencer, $share] = attributedPlace();
        $unrelated = Share::factory()->create();

        $redemption = issuer()->issue(activeOfferAt($place), User::factory()->create(), $unrelated->id);

        expect($redemption->attributed_share_id)->toBe($share->id)
            ->and($redemption->attributed_share_id)->not->toBe($unrelated->id)
            ->and($redemption->attributed_influencer_id)->toBe($influencer->id);
    });

    it('survives the share being deleted afterwards', function () {
        [$place, $influencer, $share] = attributedPlace();
        $redemption = issuer()->issue(activeOfferAt($place), User::factory()->create(), $share->id);

        $share->delete();

        // The FK is SET NULL, but the INFLUENCER — who gets paid — is untouched.
        // T-044's ledger rows are the immutable copy; nothing joins through
        // `shares` at payout time (02 §3.14).
        $redemption->refresh();
        expect($redemption->attributed_influencer_id)->toBe($influencer->id)
            ->and($redemption->attributed_share_id)->toBeNull();
    });

    it('issues with null attribution for a place nobody shared', function () {
        $place = Place::factory()->active()->create();

        $redemption = issuer()->issue(activeOfferAt($place), User::factory()->create());

        expect($redemption->attributed_influencer_id)->toBeNull()
            ->and($redemption->attributed_share_id)->toBeNull()
            ->and($redemption->status)->toBe(RedemptionStatus::Issued);
    });
});

describe('the anti-fraud table (06 §3)', function () {
    it('refuses an offer that is not redeemable', function () {
        $place = Place::factory()->active()->create();
        $offer = Offer::factory()->paused()->create(['place_id' => $place->id]);

        expect(fn () => issuer()->issue($offer, User::factory()->create()))
            ->toThrow(RedemptionInvalid::class);
    });

    /*
     * The rule PHP cannot enforce alone. Both concurrent requests pass every
     * guard; only the partial unique index stops the second, and catching that
     * violation is what turns a race into `already_issued` instead of a 500.
     */
    it('refuses a second live code for the same offer', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_user' => 5]);
        $diner = User::factory()->create();

        issuer()->issue($offer, $diner);

        $details = expectIssueRefused(fn () => issuer()->issue($offer, $diner), 'already_issued');
        expect($details['reason'])->toBe('already_issued');
        expect(Redemption::query()->count())->toBe(1);
    });

    it('refuses once the per-user quota is spent, even after the code lapsed', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_user' => 1]);
        $diner = User::factory()->create();

        // Already redeemed once — the slot is spent for good.
        Redemption::factory()->redeemed()->create(['offer_id' => $offer->id, 'user_id' => $diner->id]);

        expectIssueRefused(fn () => issuer()->issue($offer, $diner), 'user_quota_reached');
    });

    /*
     * An expired code returns its slot: a diner whose code lapsed unused never
     * actually used the offer, and locking them out would punish them for the
     * restaurant being closed that evening.
     */
    it('lets a diner retry after their previous code expired unused', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_user' => 1]);
        $diner = User::factory()->create();
        Redemption::factory()->expired()->create(['offer_id' => $offer->id, 'user_id' => $diner->id]);

        expect(issuer()->issue($offer, $diner)->status)->toBe(RedemptionStatus::Issued);
    });

    it('blocks an operator redeeming at their own venue', function () {
        $place = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

        expectIssueRefused(fn () => issuer()->issue(activeOfferAt($place), $operator), 'self_dealing');
    });

    /*
     * Keyed on the PLACE, not the offer — otherwise a venue running three
     * offers would let the same diner redeem three times a week, which is the
     * pattern the cooldown exists to stop.
     */
    it('applies the 7-day cooldown across every offer at the same venue', function () {
        $place = Place::factory()->active()->create();
        $diner = User::factory()->create();
        Redemption::factory()->redeemed()->create([
            'offer_id' => activeOfferAt($place)->id,
            'user_id' => $diner->id,
        ]);

        expectIssueRefused(fn () => issuer()->issue(activeOfferAt($place), $diner), 'cooldown');
    });

    it('lets the same diner back in once the cooldown has passed', function () {
        $place = Place::factory()->active()->create();
        $diner = User::factory()->create();
        $old = Redemption::factory()->redeemed()->create([
            'offer_id' => activeOfferAt($place)->id,
            'user_id' => $diner->id,
        ]);
        $old->forceFill(['redeemed_at' => now()->subDays(RedemptionGuards::COOLDOWN_DAYS + 1)])->save();

        expect(issuer()->issue(activeOfferAt($place), $diner)->status)->toBe(RedemptionStatus::Issued);
    });

    it('caps a diner at three issues a day across all venues', function () {
        $diner = User::factory()->create();

        for ($i = 0; $i < RedemptionGuards::MAX_ISSUES_PER_DAY; $i++) {
            issuer()->issue(activeOfferAt(Place::factory()->active()->create()), $diner);
        }

        expectIssueRefused(
            fn () => issuer()->issue(activeOfferAt(Place::factory()->active()->create()), $diner),
            'velocity_exceeded',
        );
    });

    /*
     * A refusal must not spend the diner's allowance. Otherwise three blocked
     * attempts at a paused offer would lock an honest diner out for the day.
     */
    it('does not spend the daily allowance on a refused attempt', function () {
        $place = Place::factory()->active()->create();
        $operator = User::factory()->create();
        PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

        // Three self-dealing refusals...
        for ($i = 0; $i < 3; $i++) {
            try {
                issuer()->issue(activeOfferAt($place), $operator);
            } catch (RedemptionInvalid) {
                // expected
            }
        }

        // ...leave a different, legitimate venue fully available.
        expect(issuer()->issue(activeOfferAt(Place::factory()->active()->create()), $operator)->status)
            ->toBe(RedemptionStatus::Issued);
    });

    it('refuses an offer whose venue is hidden or merged', function () {
        $survivor = Place::factory()->active()->create();
        $tombstone = Place::factory()->create([
            'status' => PlaceStatus::Merged,
            'merged_into_place_id' => $survivor->id,
        ]);

        expectIssueRefused(
            fn () => issuer()->issue(activeOfferAt($tombstone), User::factory()->create()),
            'offer_not_redeemable',
        );
    });

    /*
     * The offer row is locked before the quota counts are read, so two diners
     * issuing at once cannot both see "one slot left" and both take it. Without
     * the lock a venue that capped an offer at N a day pays for N + (requests
     * in flight) — the partial unique index only covers one diner racing
     * themselves, not two different ones.
     *
     * Serial here rather than genuinely parallel (Pest has one connection), so
     * this pins the RULE — the lock itself is what makes it hold concurrently.
     */
    it('never issues past the lifetime quota', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_total' => 2, 'quota_per_day' => null]);

        issuer()->issue($offer, User::factory()->create());
        issuer()->issue($offer, User::factory()->create());
        // The counter cache T-043 does not yet maintain is what `isRedeemable()`
        // reads, so make it true the way the redemption pipeline will.
        $offer->forceFill(['redemptions_count' => 2])->save();

        expectIssueRefused(
            fn () => issuer()->issue($offer->fresh(), User::factory()->create()),
            'offer_not_redeemable',
        );
    });

    it('takes a row lock on the offer before counting its quotas', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_day' => 5]);

        $locking = collect();
        DB::listen(function ($query) use ($locking) {
            if (str_contains($query->sql, 'from "offers"') && str_contains($query->sql, 'for update')) {
                $locking->push($query->sql);
            }
        });

        issuer()->issue($offer, User::factory()->create());

        expect($locking)->not->toBeEmpty();
    });

    it("respects the offer's per-day quota", function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_per_day' => 1]);
        issuer()->issue($offer, User::factory()->create());

        expectIssueRefused(
            fn () => issuer()->issue($offer, User::factory()->create()),
            'offer_not_redeemable',
        );
    });
});

/**
 * @return array<string, mixed>
 */
function expectIssueRefused(Closure $call, string $reason): array
{
    try {
        $call();
    } catch (RedemptionInvalid $e) {
        expect($e->details()['reason'])->toBe($reason);

        return $e->details();
    }

    throw new RuntimeException("Expected a RedemptionInvalid with reason '{$reason}', but nothing was thrown.");
}
