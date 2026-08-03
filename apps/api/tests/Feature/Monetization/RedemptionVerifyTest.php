<?php

use App\Enums\RedemptionStatus;
use App\Events\RedemptionVerified;
use App\Exceptions\RedemptionInvalid;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\Redemption;
use App\Models\User;
use App\Notifications\RedemptionConfirmed;
use App\Services\Redemptions\RedemptionCode;
use App\Services\Redemptions\RedemptionGuards;
use App\Services\Redemptions\RedemptionVerifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Honouring a code (T-043, 06 §3) — the exactly-once path.
 *
 * The organising property: **a code is worth money exactly once.** Staff
 * double-tap, retry over café wifi, and two devices scan the same QR seconds
 * apart; every one of those must leave ONE redeemed row, because that row is
 * what a restaurant is billed for and what an influencer earns from. Each test
 * here is a way that guarantee could be lost.
 */
/**
 * Assert a call fails with a specific machine-readable reason.
 *
 * The reason is what both clients branch on (03 §3.4) — "wrong restaurant" and
 * "already used" are a mistake and a possible fraud attempt, and they read very
 * differently at a till — so the tests assert it rather than the prose.
 *
 * @return array<string, mixed> the thrown exception's details, for extra checks
 */
function expectRedemptionRefused(Closure $call, string $reason): array
{
    try {
        $call();
    } catch (RedemptionInvalid $e) {
        expect($e->details()['reason'])->toBe($reason);

        return $e->details();
    }

    throw new RuntimeException("Expected a RedemptionInvalid with reason '{$reason}', but nothing was thrown.");
}

function venueWithOperator(): array
{
    $place = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    return [$place, $operator];
}

function liveCode(Place $place, ?User $diner = null, string $code = 'ABCD1234EF'): Redemption
{
    $offer = Offer::factory()->active()->create(['place_id' => $place->id]);

    return Redemption::factory()->withCode($code)->create([
        'offer_id' => $offer->id,
        'user_id' => $diner?->id ?? User::factory(),
    ]);
}

describe('the exactly-once guarantee', function () {
    it('marks a live code redeemed', function () {
        [$place, $operator] = venueWithOperator();
        $redemption = liveCode($place);

        $result = app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place, 38.7223, -9.1393);

        expect($result->replayed)->toBeFalse();

        $redemption->refresh();
        expect($redemption->status)->toBe(RedemptionStatus::Redeemed)
            ->and($redemption->redeemed_at)->not->toBeNull()
            ->and($redemption->redeemed_by_user_id)->toBe($operator->id)
            ->and($redemption->geofence_ok)->toBeTrue();
    });

    /*
     * THE test for this task. A second verification must return the FIRST
     * result rather than redeeming again or erroring — 03 §1 idempotency. If
     * this ever regresses, one visit bills the restaurant twice.
     */
    it('replays the prior result on a second verify and redeems only once', function () {
        [$place, $operator] = venueWithOperator();
        $redemption = liveCode($place);

        $verifier = app(RedemptionVerifier::class);
        $first = $verifier->verify($operator, 'ABCD1234EF', $place);
        $redeemedAt = $redemption->fresh()->redeemed_at;

        $second = $verifier->verify($operator, 'ABCD1234EF', $place);

        expect($first->replayed)->toBeFalse()
            ->and($second->replayed)->toBeTrue()
            // The same instant, so nothing re-flipped the row underneath.
            ->and($second->redemption->redeemed_at->equalTo($redeemedAt))->toBeTrue();

        expect(Redemption::query()->where('status', RedemptionStatus::Redeemed)->count())->toBe(1);
    });

    /*
     * The guarded UPDATE, exercised directly: a caller holding a STALE model
     * (read before someone else redeemed) must not be able to flip it again.
     * This is the read-then-write race the `where status = issued` clause
     * exists to lose safely.
     */
    it('refuses a flip from a stale read that lost the race', function () {
        [$place, $operator] = venueWithOperator();
        $redemption = liveCode($place);

        // Someone else redeems it out of band — exactly what a concurrent
        // request would have done between our read and our write.
        Redemption::query()->whereKey($redemption->id)->update([
            'status' => RedemptionStatus::Redeemed,
            'redeemed_at' => now(),
            'redeemed_by_user_id' => $operator->id,
        ]);

        $result = app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place);

        // Replayed, not double-redeemed, and still exactly one redeemed row.
        expect($result->replayed)->toBeTrue()
            ->and(Redemption::query()->where('status', RedemptionStatus::Redeemed)->count())->toBe(1);
    });

    it('dispatches RedemptionVerified exactly once, for the ledger to hook', function () {
        Event::fake([RedemptionVerified::class]);
        [$place, $operator] = venueWithOperator();
        liveCode($place);

        $verifier = app(RedemptionVerifier::class);
        $verifier->verify($operator, 'ABCD1234EF', $place);
        $verifier->verify($operator, 'ABCD1234EF', $place);

        // The replay must NOT re-dispatch — T-044 writes ledger entries here,
        // and a second event is a second fee.
        Event::assertDispatchedTimes(RedemptionVerified::class, 1);
    });
});

describe('what must be refused', function () {
    it('refuses an unknown code', function () {
        [$place, $operator] = venueWithOperator();

        expectRedemptionRefused(fn () => app(RedemptionVerifier::class)->verify($operator, 'ZZZZ9999ZZ', $place), 'not_found');
    });

    it('refuses a malformed code without touching the database', function () {
        [$place, $operator] = venueWithOperator();

        expectRedemptionRefused(fn () => app(RedemptionVerifier::class)->verify($operator, 'nope', $place), 'not_found');
    });

    /*
     * `expires_at` in the past while the column still reads `issued` — the gap
     * between a window closing and the hourly sweep running. Honouring it would
     * bill a visit 06 §2.3 says is never billable.
     */
    it('refuses a code whose window closed before the sweep caught it', function () {
        [$place, $operator] = venueWithOperator();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        $redemption = Redemption::factory()->withCode('ABCD1234EF')->overdue()
            ->create(['offer_id' => $offer->id]);

        expect($redemption->status)->toBe(RedemptionStatus::Issued);
        expectRedemptionRefused(fn () => app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place), 'expired');

        expect($redemption->fresh()->status)->toBe(RedemptionStatus::Issued);
    });

    /*
     * A real code for another venue. Checked BEFORE the already-redeemed
     * replay, so a restaurant is never shown another venue's redemption — even
     * one they legitimately hold the code for.
     */
    it('refuses a code belonging to another venue', function () {
        [$mine, $operator] = venueWithOperator();
        $theirs = Place::factory()->active()->create();
        liveCode($theirs);

        expectRedemptionRefused(fn () => app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $mine), 'wrong_place');
    });

    it('refuses a voided code', function () {
        [$place, $operator] = venueWithOperator();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id]);
        Redemption::factory()->withCode('ABCD1234EF')->void()->create(['offer_id' => $offer->id]);

        expectRedemptionRefused(fn () => app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place), 'not_live');
    });
});

describe('the geofence', function () {
    it('refuses a device far from the venue and records the distance', function () {
        [$place, $operator] = venueWithOperator();
        liveCode($place);

        // Porto — ~275 km from the Lisbon venue.
        $details = expectRedemptionRefused(
            fn () => app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place, 41.1579, -8.6291),
            'outside_geofence',
        );
        expect($details['distance_m'])->toBeGreaterThan(200_000);

        expect(Redemption::firstOrFail()->status)->toBe(RedemptionStatus::Issued);
    });

    /*
     * A missing location must NOT block. Staff deny the permission and indoor
     * GPS fails; a restaurant unable to serve a customer over it is worse than
     * an unverified reading, and 06 §3 accepts spoofing as a v1 residual risk
     * anyway — the durable value is the audit trail.
     */
    it('allows a verification with no location and records it as unknown', function () {
        [$place, $operator] = venueWithOperator();
        liveCode($place);

        app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place);

        $redemption = Redemption::firstOrFail();
        expect($redemption->status)->toBe(RedemptionStatus::Redeemed)
            ->and($redemption->geofence_ok)->toBeNull()
            ->and($redemption->geofence_distance_m)->toBeNull();
    });

    it('records the measured distance on a pass', function () {
        [$place, $operator] = venueWithOperator();
        liveCode($place);

        // ~100 m away — inside the 500 m radius.
        app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place, 38.7232, -9.1393);

        $redemption = Redemption::firstOrFail();
        expect($redemption->geofence_ok)->toBeTrue()
            ->and($redemption->geofence_distance_m)->toBeLessThan(500)
            ->and($redemption->geofence_distance_m)->toBeGreaterThan(0);
    });
});

describe('staff velocity', function () {
    it('stops a staff account grinding through codes', function () {
        [$place, $operator] = venueWithOperator();
        $verifier = app(RedemptionVerifier::class);

        // Burn the hourly budget on codes that do not exist — a guessing run.
        for ($i = 0; $i < RedemptionGuards::MAX_VERIFIES_PER_HOUR; $i++) {
            try {
                $verifier->verify($operator, 'ZZZZ9999ZZ', $place);
            } catch (Throwable) {
                // not_found, as expected — the attempt still counts.
            }
        }

        liveCode($place);

        expectRedemptionRefused(fn () => $verifier->verify($operator, 'ABCD1234EF', $place), 'staff_velocity_exceeded');
    });
});

describe('code normalization', function () {
    /*
     * Crockford's whole point: a code read aloud and typed back must match.
     * Someone who hears "oh" and types O for a 0 gets a match, or the diner is
     * told their perfectly valid code does not exist.
     */
    it('accepts the display grouping, lower case, and confusable characters', function (string $typed) {
        [$place, $operator] = venueWithOperator();
        liveCode($place);

        $result = app(RedemptionVerifier::class)->verify($operator, $typed, $place);

        expect($result->replayed)->toBeFalse();
    })->with([
        'ABCD1234EF',
        'abcd1234ef',
        'ABCD-1234-EF',
        ' abcd 1234 ef ',
    ]);

    it('folds O to 0 and I/L to 1 the way Crockford specifies', function () {
        // The four characters the alphabet omits precisely because a person
        // cannot reliably tell them from 0 and 1 on a printed receipt.
        expect(RedemptionCode::normalize('OIL0'))->toBe('0110')
            ->and(RedemptionCode::normalize('abcd-1234-ef'))->toBe('ABCD1234EF');
    });

    it('rejects a string of the right length that is not in the alphabet', function () {
        // U is excluded from Crockford's alphabet and is NOT folded, so a code
        // containing one was never issued by us.
        expect(RedemptionCode::isWellFormed('ABCDU234EF'))->toBeFalse()
            ->and(RedemptionCode::isWellFormed('ABCD1234EF'))->toBeTrue()
            ->and(RedemptionCode::isWellFormed('ABCD1234E'))->toBeFalse();
    });
});

describe('the diner is told', function () {
    it('notifies the diner when their code is honoured', function () {
        Notification::fake();
        [$place, $operator] = venueWithOperator();
        $diner = User::factory()->create();
        liveCode($place, $diner);

        app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place);

        Notification::assertSentTo($diner, RedemptionConfirmed::class, function (RedemptionConfirmed $n) use ($diner) {
            $payload = $n->toDatabase($diner);

            // One payload, both channels — the center row and the push cannot
            // drift into different copy for the same event (T-040).
            return $payload['type'] === 'redemption.verified'
                && str_starts_with($payload['url'], '/redemptions/');
        });
    });

    it('does not notify on a replay', function () {
        Notification::fake();
        [$place, $operator] = venueWithOperator();
        $diner = User::factory()->create();
        liveCode($place, $diner);

        $verifier = app(RedemptionVerifier::class);
        $verifier->verify($operator, 'ABCD1234EF', $place);
        $verifier->verify($operator, 'ABCD1234EF', $place);

        // A second "your offer was redeemed" for one visit reads as a second
        // charge to the diner.
        Notification::assertSentToTimes($diner, RedemptionConfirmed::class, 1);
    });
});
