<?php

use App\Enums\RedemptionStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ledger\RedemptionVoider;
use App\Services\Redemptions\OfferQuotaCounter;
use App\Services\Redemptions\OfferQuotaReconciler;
use App\Services\Redemptions\RedemptionIssuer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/**
 * `offers.redemptions_count` — the number a restaurant's money depends on
 * (T-127, 06 §2.2).
 *
 * The organising property, and the one T-042 → T-127 spent a phase not having:
 * **the counter is a claim on a slot, never a statistic.** Every unit of it is a
 * redemption row that exists and still holds its slot; every row that stops
 * holding one — voided, expired — gives it back; and nothing else may move it.
 *
 * So nothing below fabricates the counter. These tests drive
 * `POST /api/v1/redemptions`, the endpoint a phone calls, and then read the
 * surfaces an operator and a diner actually see. The distinction matters here
 * more than anywhere else in the suite: the RULE ("51 > 50, refuse") was tested
 * from the day it was written — against an offer whose counter a factory had
 * hand-set — and stayed green for a whole phase while no code on any path
 * maintained the column at all.
 */

/** Issue for real, over HTTP, as this diner. */
function issueOverHttp(User $diner, Offer $offer): TestResponse
{
    return test()->actingAs($diner)->postJson('/api/v1/redemptions', ['offer_id' => $offer->id]);
}

/**
 * The same, for a diner nobody has seen before.
 *
 * Distinct diners rather than one repeating, everywhere below: 06 §3 caps a
 * single diner at 3 issues a day, one live code per offer, and one visit per
 * venue per week, and the issue throttle keys on the account. A loop over one
 * diner would be testing the anti-fraud table instead of the offer's lifetime
 * cap, and would be refused long before the quota was reached.
 */
function issueAsNewDiner(Offer $offer): TestResponse
{
    return issueOverHttp(User::factory()->create(), $offer);
}

/** The offer as a client sees it — the JSON, not the model. */
function offerAsSeen(Offer $offer): array
{
    return test()->getJson("/api/v1/offers/{$offer->id}")->assertOk()->json('data');
}

/**
 * `offers.updated_at` exactly as the table holds it — read past Eloquent, since
 * a model that has just been written is only ever agreeing with itself.
 */
function storedUpdatedAt(Offer $offer): string
{
    return (string) DB::table('offers')->where('id', $offer->id)->value('updated_at');
}

/** The venue's map pin, which is where a diner is told the offer exists at all. */
function mapPinFor(Place $place): array
{
    $pins = test()->getJson('/api/v1/map/places?bbox=-9.2,38.70,-9.10,38.75&zoom=16')
        ->assertOk()
        ->json('data.pins');

    $pin = collect($pins)->firstWhere('id', (string) $place->id);

    if ($pin === null) {
        // Otherwise a place that fell out of the viewport reads as "undefined
        // array key has_active_offer", which looks like a schema change.
        throw new RuntimeException("No map pin for place {$place->id} in the viewport.");
    }

    return $pin;
}

/**
 * Stand in for the second connection Pest does not have: run `$whenItHappens`
 * once, the first time a query containing every one of `$sqlContains` is issued
 * — i.e. inside the window between another writer's read and its write.
 *
 * @return Closure(): bool whether it actually fired. A test whose interception
 *                         never ran is asserting an ordinary, unraced run.
 */
function interceptOnce(Closure $whenItHappens, string ...$sqlContains): Closure
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $whenItHappens, $sqlContains): void {
        foreach ($sqlContains as $needle) {
            if ($fired || ! str_contains($query->sql, $needle)) {
                return;
            }
        }

        // Set before the action so the listener does not re-enter on its writes.
        $fired = true;
        $whenItHappens();
    });

    // By reference, deliberately: an arrow function captures `$fired` by value
    // — `false`, forever — so the reader would deny an interception that had in
    // fact fired.
    return function () use (&$fired): bool {
        return $fired;
    };
}

/**
 * The diner walks in and the till honours `$code` in the gap between the expiry
 * sweep reading its chunk and writing it back.
 *
 * @return Closure(): bool
 */
function honouredInTheSweepsGap(Redemption $code): Closure
{
    return interceptOnce(
        fn () => $code->forceFill(['status' => RedemptionStatus::Redeemed, 'redeemed_at' => now()])->save(),
        'select "id", "offer_id" from "redemptions"',
    );
}

describe('the 51st diner', function () {
    /*
     * The task's headline, and the shape of the bug it closes: "first 50 diners
     * get a free dessert" is a promise a restaurant pays for, and before T-127
     * the 51st, the 500th and the 5000th all succeeded.
     *
     * Fifty-one real requests from fifty-one real accounts. Nothing is shortened
     * to a smaller quota or reached through a factory state, because that is
     * exactly the shortcut that kept this green while it was broken.
     */
    it('refuses the 51st redemption of a 50-slot offer, issued over HTTP', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 50]);

        foreach (range(1, 50) as $ignored) {
            issueAsNewDiner($offer)->assertCreated();
        }

        expect($offer->refresh()->redemptions_count)->toBe(50);

        issueAsNewDiner($offer)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'redemption_invalid')
            // The reason is the instruction: "sold out", not "try again later".
            ->assertJsonPath('error.details.reason', 'offer_not_redeemable');

        // Exactly fifty codes exist. A counter that merely REPORTED 50 while a
        // 51st row landed would be the same bug wearing a warning label.
        expect(Redemption::query()->count())->toBe(50)
            ->and($offer->refresh()->redemptions_count)->toBe(50);
    });
});

describe('what the client is told', function () {
    it('counts up as diners take slots, and reports the remainder truthfully', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 3]);

        expect(offerAsSeen($offer))
            ->toMatchArray(['redemptions_count' => 0, 'remaining_quota' => 3, 'is_redeemable' => true]);

        issueAsNewDiner($offer)->assertCreated();
        issueAsNewDiner($offer)->assertCreated();

        // The operator's headline number and the diner's scarcity signal are the
        // same field, so it has to be true for both of them.
        expect(offerAsSeen($offer))
            ->toMatchArray(['redemptions_count' => 2, 'remaining_quota' => 1, 'is_redeemable' => true]);

        issueAsNewDiner($offer)->assertCreated();

        expect(offerAsSeen($offer))
            ->toMatchArray(['redemptions_count' => 3, 'remaining_quota' => 0, 'is_redeemable' => false]);
    });

    it('keeps counting on an uncapped offer while reporting no remainder', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => null]);

        issueAsNewDiner($offer)->assertCreated();
        issueAsNewDiner($offer)->assertCreated();

        // `null` remaining means unlimited, which `quota_total - count` cannot
        // express — but the COUNT is still a real count, and it is what the
        // operator reads to decide whether the promotion is working.
        expect(offerAsSeen($offer))
            ->toMatchArray(['redemptions_count' => 2, 'remaining_quota' => null, 'is_redeemable' => true]);
    });
});

describe('a slot comes back', function () {
    /*
     * The promise `Offer::hasTotalQuotaLeft()`'s docblock has been making since
     * T-042, and which nothing kept until now: a redemption that stops holding
     * its slot returns it, otherwise a run of disputes and abandoned codes
     * silently retires an offer the restaurant is still paying to run.
     */
    it('returns the slot of a voided redemption, and lets the next diner in', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_total' => 1]);
        $operator = operatorOfPlace($place);

        $code = issueAsNewDiner($offer)->assertCreated()->json('data.code');
        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', ['code' => $code, 'place_id' => $place->id])
            ->assertOk();

        // Sold out, on the only surface a client can check.
        expect(offerAsSeen($offer))->toMatchArray(['remaining_quota' => 0, 'is_redeemable' => false]);
        issueAsNewDiner($offer)->assertStatus(422)->assertJsonPath('error.details.reason', 'offer_not_redeemable');

        // The restaurant disputes it: a wrong scan is not a visit, the fee is
        // reversed — so the slot cannot stay spent either.
        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'wrong scan');

        expect($offer->refresh()->redemptions_count)->toBe(0)
            ->and(offerAsSeen($offer))->toMatchArray(['remaining_quota' => 1, 'is_redeemable' => true]);

        issueAsNewDiner($offer)->assertCreated();
        expect(Redemption::query()->count())->toBe(2)
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    it('returns the slots the expiry sweep retires — and only those', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_total' => 2]);
        $operator = operatorOfPlace($place);

        $walkedIn = issueAsNewDiner($offer)->assertCreated()->json('data.code');
        issueAsNewDiner($offer)->assertCreated();

        expect($offer->refresh()->redemptions_count)->toBe(2);
        issueAsNewDiner($offer)->assertStatus(422);

        // One diner actually walked in and was served, before either code lapsed.
        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', ['code' => $walkedIn, 'place_id' => $place->id])
            ->assertOk();

        $this->travel(RedemptionIssuer::TTL_HOURS + 1)->hours();

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 1 redemption(s).')
            ->assertSuccessful();

        /*
         * Exactly one slot back, not two. The honoured code is past its window
         * by date as well, and it keeps its slot for good: the restaurant is
         * billed for that visit, so handing the quota back would buy the venue a
         * third free dessert against a cap of two. (`overdue()` never selects it
         * — the case where a code is honoured after the sweep has already
         * selected it is the test below.)
         */
        expect($offer->refresh()->redemptions_count)->toBe(1)
            ->and(offerAsSeen($offer))->toMatchArray(['redemptions_count' => 1, 'remaining_quota' => 1]);

        issueAsNewDiner($offer)->assertCreated();
        expect($offer->refresh()->redemptions_count)->toBe(2);
    });

    /*
     * The gap the sweep's re-check exists for, made deterministic: the diner
     * walks in and the till honours the code AFTER the sweep has read its chunk
     * and BEFORE it writes. A query listener stands in for the second
     * connection Pest does not have — what is being pinned is what the command
     * does with the number its own guarded UPDATE returned. Releasing the
     * number it ASKED for would return a slot for a visit the restaurant is
     * being billed for, which is the same free dessert twice.
     */
    it('does not return a slot for a code honoured after the sweep read it', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 2]);

        issueAsNewDiner($offer)->assertCreated();
        issueAsNewDiner($offer)->assertCreated();
        expect($offer->refresh()->redemptions_count)->toBe(2);

        $walksIn = Redemption::query()->orderBy('id')->firstOrFail();
        $this->travel(RedemptionIssuer::TTL_HOURS + 1)->hours();

        $intercepted = honouredInTheSweepsGap($walksIn);

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 1 redemption(s).')
            ->assertSuccessful();

        expect($intercepted())->toBeTrue()
            ->and($walksIn->refresh()->status)->toBe(RedemptionStatus::Redeemed)
            // One slot back for the code that really lapsed; the honoured one
            // keeps the slot it is being billed for.
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    /*
     * The sweep chunks by id, so one chunk holds codes from whichever offers
     * happen to fall in that id range — while the counter they are returned to
     * is per offer. A release that used the chunk's total, or the first offer it
     * saw, would credit one promotion with another's abandoned codes.
     */
    it('credits each offer with its own expired codes when the sweep spans several', function () {
        $twoLapsed = activeOfferAt(attributes: ['quota_total' => 5]);
        $oneLapsed = activeOfferAt(attributes: ['quota_total' => 5]);

        issueAsNewDiner($twoLapsed)->assertCreated();
        issueAsNewDiner($twoLapsed)->assertCreated();
        issueAsNewDiner($oneLapsed)->assertCreated();

        expect($twoLapsed->refresh()->redemptions_count)->toBe(2)
            ->and($oneLapsed->refresh()->redemptions_count)->toBe(1);

        $this->travel(RedemptionIssuer::TTL_HOURS + 1)->hours();

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 3 redemption(s).')
            ->assertSuccessful();

        expect($twoLapsed->refresh()->redemptions_count)->toBe(0)
            ->and($oneLapsed->refresh()->redemptions_count)->toBe(0);
    });
});

describe('the sweep gives slots back atomically', function () {
    /*
     * The flip and the release are two writes that mean one thing. Left as two
     * auto-committed statements, a crash, a deploy or a killed worker between
     * them leaves the codes reading `expired` while the offer still holds their
     * slots — and NOTHING self-heals from there. `reelmap:offers:reconcile-quotas`
     * reports the counter as healthy, because it recomputes from the rows and the
     * rows say `expired`; `--fix` writes the same wrong number back. The venue
     * quietly stops being badged on the map for a promotion it is paying for.
     *
     * A transaction is invisible until something inside it fails, so the only
     * way to tell the fix from the bug is to MAKE the second write fail: a
     * counter whose `release()` throws, bound over the real one for the length
     * of the sweep. If the flip is inside the same transaction it unwinds with
     * it and the codes are still `issued`; if it is not, they are `expired` and
     * their slots are gone for good.
     */
    it('rolls the expiry flip back when the slot cannot be returned', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 5]);
        issueAsNewDiner($offer)->assertCreated();
        issueAsNewDiner($offer)->assertCreated();
        expect($offer->refresh()->redemptions_count)->toBe(2);

        $this->travel(RedemptionIssuer::TTL_HOURS + 1)->hours();

        // Only `release()` is replaced. The two claims above went through the
        // real writer, so the counter this is about is a real counter.
        $this->app->instance(OfferQuotaCounter::class, new class extends OfferQuotaCounter
        {
            public function release(int $offerId, int $slots = 1): void
            {
                throw new RuntimeException('the slot could not be returned');
            }
        });

        expect(fn () => Artisan::call('reelmap:redemptions:expire'))
            ->toThrow(RuntimeException::class, 'the slot could not be returned');

        // Still `issued`, still holding their slots — the two halves agree
        // about the world even though the run died between them.
        expect(Redemption::query()->where('status', RedemptionStatus::Issued)->count())->toBe(2)
            ->and(Redemption::query()->where('status', RedemptionStatus::Expired)->count())->toBe(0)
            ->and($offer->refresh()->redemptions_count)->toBe(2);

        // And so the next sweep finds them and finishes the job, which is the
        // entire reason for unwinding rather than pressing on: `overdue()` only
        // selects rows that still read `issued`.
        $this->app->forgetInstance(OfferQuotaCounter::class);

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 2 redemption(s).')
            ->assertSuccessful();

        expect($offer->refresh()->redemptions_count)->toBe(0)
            ->and(offerAsSeen($offer))->toMatchArray(['remaining_quota' => 5, 'is_redeemable' => true]);
    });

    /*
     * The `release($offerId, 0)` path, reached from the command rather than by
     * calling the counter directly — the only way it happens in production.
     * Every code in the group was honoured in the gap, so the guarded UPDATE
     * flips nothing and the sweep asks for nothing back.
     *
     * Zero is not drift, so it must not alert: this condition happens whenever a
     * diner walks in during the second the sweep is running, and a monitor that
     * fired on it would be noise on a normal night.
     */
    it('asks for no slots back when the whole group was honoured in the gap', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 2]);
        issueAsNewDiner($offer)->assertCreated();
        expect($offer->refresh()->redemptions_count)->toBe(1);

        $walksIn = Redemption::query()->firstOrFail();
        $this->travel(RedemptionIssuer::TTL_HOURS + 1)->hours();

        $intercepted = honouredInTheSweepsGap($walksIn);

        Log::spy();

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 0 redemption(s).')
            ->assertSuccessful();

        expect($intercepted())->toBeTrue()
            // The honoured code keeps the slot the restaurant is billed for.
            ->and($offer->refresh()->redemptions_count)->toBe(1);

        Log::shouldNotHaveReceived('warning', ['offer.quota_counter_drift', Mockery::any()]);
    });

    /*
     * Past one `chunkById(500)` page, which is the only thing this many rows
     * buys — and it buys two distinct properties that no smaller set can reach.
     *
     * First, the sweep MUTATES the rows it is selecting: `overdue()` matches
     * `issued` only, and the sweep flips them to `expired`, so the result set
     * shrinks underneath the iteration. `chunkById` survives that because each
     * page is `where id > lastSeen`; a plain `chunk()`, which is a one-word
     * edit away, pages by OFFSET and would skip the entire second half of every
     * sweep this size. Silently — the command would report half the codes and
     * exit 0, and the slots it missed would never come back.
     *
     * Second, the two offers' codes are interleaved by id, so one of them
     * appears in BOTH pages and is released twice, in two separate per-group
     * transactions. That is the "an offer can recur across chunks" case the
     * grouping comment claims, and the arithmetic only comes out if each release
     * carries that page's own count.
     */
    it('returns every slot when the sweep runs longer than one chunk', function () {
        $recurs = activeOfferAt(attributes: ['quota_total' => 400]);
        $singlePage = activeOfferAt(attributes: ['quota_total' => 400]);

        // 501 rows: one past the chunk size, so there is a second page holding
        // exactly one code — and it belongs to the offer the first page already
        // credited.
        $rows = 501;
        $counter = app(OfferQuotaCounter::class);

        // Seeded straight into the table rather than issued over HTTP: 501
        // requests is a load test, not a unit of behaviour, and what is under
        // test here is the sweep's paging. The slots are still claimed through
        // the production writer, so the counters are real and the release path
        // the sweep takes is the ordinary one.
        $diners = User::factory()->count((int) ceil($rows / 2))->create();
        $lapsed = [];

        foreach (range(0, $rows - 1) as $i) {
            // Alternating, so `recurs` owns the odd positions including the very
            // last row — the one that lands alone on page two.
            $offer = $i % 2 === 0 ? $singlePage : $recurs;
            $offer = $i === $rows - 1 ? $recurs : $offer;

            $lapsed[] = [
                'offer_id' => $offer->id,
                // One diner may hold one live code per offer, so they are reused
                // across the two offers and never within one.
                'user_id' => $diners[intdiv($i, 2)]->id,
                'code' => str_pad((string) $i, 10, 'S', STR_PAD_LEFT),
                'qr_payload' => "v1.seed-{$i}",
                'status' => RedemptionStatus::Issued->value,
                'issued_at' => now()->subDays(2),
                'expires_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            expect($counter->claim($offer->id))->toBeTrue();
        }

        DB::table('redemptions')->insert($lapsed);

        $recursHolds = $recurs->refresh()->redemptions_count;
        $singlePageHolds = $singlePage->refresh()->redemptions_count;
        expect($recursHolds + $singlePageHolds)->toBe($rows);

        Log::spy();

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain("Expired {$rows} redemption(s).")
            ->assertSuccessful();

        // Every code retired and every slot back, on both offers. A sweep that
        // paged by offset would have expired ~250 of the 501 and left the rest
        // holding slots forever.
        expect(Redemption::query()->where('status', RedemptionStatus::Issued)->count())->toBe(0)
            ->and($recurs->refresh()->redemptions_count)->toBe(0)
            ->and($singlePage->refresh()->redemptions_count)->toBe(0);

        // Every release was an ordinary one. A per-group count that came from
        // the chunk rather than the group would have over-released one offer and
        // under-released the other, and the over-released one lands here.
        Log::shouldNotHaveReceived('warning', ['offer.quota_counter_drift', Mockery::any()]);
    });
});

describe('the claim itself', function () {
    /*
     * Serial rather than genuinely parallel — Pest has one connection — so what
     * this pins is the RULE. The row lock in RedemptionIssuer is what makes it
     * hold when two requests are really in flight; the guarded UPDATE below is
     * what makes it hold for any caller that reaches the counter without taking
     * that lock.
     */
    it('lets exactly one of two diners take the last slot', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 1]);

        issueAsNewDiner($offer)->assertCreated();
        issueAsNewDiner($offer)
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'offer_not_redeemable');

        expect(Redemption::query()->count())->toBe(1)
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    /*
     * `claim()` takes an ID, not a model — and this is what that buys. The whole
     * decision is the UPDATE's own WHERE clause against the row as the database
     * currently holds it, so there is no in-memory copy of the counter anywhere
     * on the path that a caller could be holding an out-of-date version of.
     *
     * The offer read below is the demonstration, not the mechanism: it is loaded
     * before either claim and never refreshed, and its `redemptions_count` is
     * re-read BETWEEN the two claims to show it is genuinely stale by then —
     * still 0 while the table says 1. The second claim is refused anyway. That
     * ordering is the assertion; the same expectation written before the first
     * claim would only be restating the factory default.
     */
    it('decides a claim from the stored row, with no counter held in memory to be stale', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 1]);
        $readBeforeAnyClaim = Offer::query()->findOrFail($offer->id);

        $counter = app(OfferQuotaCounter::class);

        expect($counter->claim($offer->id))->toBeTrue()
            // Stale as of right now: the slot is gone and this copy says it is
            // free. A claim that trusted a caller's model would grant it.
            ->and($readBeforeAnyClaim->redemptions_count)->toBe(0)
            ->and($counter->claim($readBeforeAnyClaim->id))->toBeFalse()
            // The refused claim did not move the counter on its way out.
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    it('never refuses a claim against an uncapped offer', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => null]);
        $counter = app(OfferQuotaCounter::class);

        expect($counter->claim($offer->id))->toBeTrue()
            ->and($counter->claim($offer->id))->toBeTrue()
            ->and($offer->refresh()->redemptions_count)->toBe(2);
    });

    /*
     * The belt-and-braces branch itself: the refusal `RedemptionIssuer` checks
     * for and which the row lock makes unreachable in ordinary operation. It is
     * reached here the only way it ever could be — another writer takes the last
     * slot AFTER this request has read the offer and BEFORE its own claim lands
     * — by hooking the lock read and claiming from inside it.
     *
     * The stand-in writer shares Pest's single connection, so its claim rolls
     * back with the refused transaction; what is pinned is the REFUSAL, not
     * where the other writer's counter ends up. Without the cap in the claim's
     * WHERE, this request issues a second code against a quota of one.
     */
    it('refuses at the claim when the last slot goes between the read and the write', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 1]);

        Log::spy();

        $taken = interceptOnce(
            fn () => expect(app(OfferQuotaCounter::class)->claim($offer->id))->toBeTrue(),
            'from "offers"',
            'for update',
        );

        issueAsNewDiner($offer)
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'offer_not_redeemable');

        expect($taken())->toBeTrue()
            // No code was handed out, and the counter carries nothing from the
            // rolled-back attempt.
            ->and(Redemption::query()->count())->toBe(0)
            ->and($offer->refresh()->redemptions_count)->toBe(0);

        // The 422 this produced is byte-identical to the one an ordinary
        // sold-out offer returns, so without this record a LOST ROW LOCK is
        // indistinguishable — in every log and every metric — from a popular
        // promotion. It carries what the locked read believed, which is the
        // only evidence of the disagreement that is left afterwards.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'offer.quota_claim_refused_under_lock'
                && $context['offer_id'] === $offer->id
                && $context['redemptions_count'] === 0
                && $context['quota_total'] === 1)
            ->once();
    });

    /*
     * The claim rolls back with the transaction it sits in. `quota_per_user` is
     * 5 here so the PHP guards pass and the request reaches the INSERT — where
     * the partial unique index refuses a second live code for the same diner
     * (06 §3). The slot has already been taken by then, so if it did not come
     * back out with the rollback the offer would permanently lose a slot to a
     * redemption that does not exist.
     */
    it('carries the slot back out when the insert is refused', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 10, 'quota_per_user' => 5]);
        $diner = User::factory()->create();

        issueOverHttp($diner, $offer)->assertCreated();
        expect($offer->refresh()->redemptions_count)->toBe(1);

        // 409, not 422: the diner is holding the code already — a conflict with
        // the state they are in, and a different instruction from "sold out".
        issueOverHttp($diner, $offer)
            ->assertStatus(409)
            ->assertJsonPath('error.details.reason', 'already_issued');

        expect($offer->refresh()->redemptions_count)->toBe(1)
            ->and(Redemption::query()->count())->toBe(1);
    });

    /*
     * ONE write, per issue, forever. A second `update "offers"` here would mean
     * the counter is being moved somewhere other than the claim — a PHP read
     * followed by a save, a model event, a second increment on a retry — and any
     * of those is a slot taken twice for one code.
     *
     * Only the count: the generated SQL itself is deliberately not asserted,
     * because that pins Postgres' quoting rather than behaviour. That the cap is
     * a predicate of the WRITE and not a PHP check followed by an unconditional
     * `+1` is proven behaviourally by the refusal test above, where the last
     * slot goes between the read and the write.
     */
    it('moves the counter exactly once per issue', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 2]);

        $claims = collect();
        DB::listen(function ($query) use ($claims) {
            if (str_contains($query->sql, 'update "offers"') && str_contains($query->sql, 'redemptions_count')) {
                $claims->push($query->sql);
            }
        });

        issueAsNewDiner($offer)->assertCreated();

        expect($claims)->toHaveCount(1)
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });
});

describe('a release that cannot be honoured', function () {
    /*
     * The same `>= slots` floor FollowController and BlockUsers use, for the
     * same reason: a counter that has already drifted must not be made worse by
     * a correction, and the CHECK constraint would refuse the write anyway.
     * Reported rather than clamped, because the reconciler is the repair and a
     * silent clamp is how it would never get run.
     */
    it('refuses to drive the counter below zero, and says so out loud', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 5]);
        issueAsNewDiner($offer)->assertCreated();

        Log::spy();
        app(OfferQuotaCounter::class)->release($offer->id, 2);

        expect($offer->refresh()->redemptions_count)->toBe(1);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'offer.quota_counter_drift'
                // Why the key set is what it is: `OfferQuotaCounter::release()`.
                && $context['source'] === 'release'
                && $context['offer_id'] === $offer->id
                && $context['slots_to_release'] === 2)
            ->once();
    });

    /*
     * A no-op means NO WRITE, which is why the statements are counted rather
     * than the end state. `decrement($column, 0)` leaves the counter where it
     * was, so an assertion on the number alone passes with the `$slots < 1`
     * guard deleted — the UPDATE still runs, still matches the row, and still
     * returns 1, so it does not even reach the drift log.
     *
     * The negative case is why the guard is `< 1` and not `=== 0`: without it,
     * `decrement(-3)` is an INCREMENT, and `redemptions_count >= -3` matches
     * every row there is. Something asking for slots BACK would silently take
     * three more, against the one cap this class exists to hold.
     */
    it('attempts no write at all when there is nothing to give back', function () {
        $offer = activeOfferAt(attributes: ['quota_total' => 5]);
        issueAsNewDiner($offer)->assertCreated();

        $writes = collect();
        DB::listen(function ($query) use ($writes): void {
            if (str_contains($query->sql, 'update "offers"')) {
                $writes->push($query->sql);
            }
        });

        // Zero is the number the expiry sweep passes when its guarded UPDATE
        // flipped nothing — every code in that group was honoured in the gap.
        app(OfferQuotaCounter::class)->release($offer->id, 0);
        app(OfferQuotaCounter::class)->release($offer->id, -3);

        expect($writes)->toBeEmpty()
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });
});

describe("the operator's own timestamp", function () {
    /*
     * `updated_at` is the operator's signal about their own content: it sorts
     * their offer list and keys the caches over it. The counter is derived from
     * the redemption rows, so a diner taking a slot at 3am is bookkeeping, not
     * an edit of the offer — which is why `OfferQuotaReconciler::repair()` pins
     * the column (`updated_at = offers.updated_at`) when it corrects drift. The
     * two writers of the counter have to agree about that, or the same stamp
     * means one thing after the nightly run and another after every scan.
     *
     * The clock is moved a whole hour before each write, and the value is read
     * back out of the table rather than off the model. So this cannot pass
     * because both timestamps landed in the same second, nor because Eloquent
     * handed back what it already had in memory: if either write carried an
     * `updated_at` the comparison is an hour out, not a rounding.
     */
    it('is untouched by the claim a redemption takes, and by the release a void gives back', function () {
        $place = Place::factory()->active()->create();
        $offer = activeOfferAt($place, ['quota_total' => 1]);
        $operator = operatorOfPlace($place);
        $lastEdited = storedUpdatedAt($offer);

        $this->travel(1)->hour();

        $code = issueAsNewDiner($offer)->assertCreated()->json('data.code');

        // The counter is asserted alongside the stamp at both steps, because a
        // claim that never happened would leave `updated_at` alone too — that
        // is the way this test could go green while testing nothing.
        expect($offer->refresh()->redemptions_count)->toBe(1)
            ->and(storedUpdatedAt($offer))->toBe($lastEdited);

        $this->actingAs($operator)
            ->postJson('/api/v1/redemptions/verify', ['code' => $code, 'place_id' => $place->id])
            ->assertOk();

        $this->travel(1)->hour();

        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'wrong scan');

        expect($offer->refresh()->redemptions_count)->toBe(0)
            ->and(storedUpdatedAt($offer))->toBe($lastEdited);
    });
});

describe('the surfaces that read the counter', function () {
    /*
     * The wiring seam T-127 exists for. A maintained counter no read path
     * consults is just a number in a column: the map badge is where a diner is
     * told a venue has something running.
     *
     * Badge and claim are the same question asked twice, so they are spelled
     * once — `Offer::scopeNotSoldOut()`, which `PlaceQueryBuilder` filters the
     * map on and `OfferQuotaCounter::claim()` carries as its UPDATE's own
     * precondition. What is asserted here is the property that buys: the badge
     * turns off at exactly the slot the claim starts refusing, and back on at
     * exactly the slot it starts granting. Two copies of the predicate would
     * drift, and every hour they disagreed would send a diner to a counter for
     * a refusal.
     */
    it('stops badging a sold-out venue on the map, and badges it again when a slot returns', function () {
        $place = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
        $offer = activeOfferAt($place, ['quota_total' => 1]);

        expect(mapPinFor($place)['has_active_offer'])->toBeTrue();

        $code = issueAsNewDiner($offer)->assertCreated()->json('data.code');

        // Live by status and window, spent by quota — advertising it now sends
        // someone to a counter for a refusal.
        expect(mapPinFor($place)['has_active_offer'])->toBeFalse();

        // Honoured at the till, then disputed and voided: the real path back.
        $this->actingAs(operatorOfPlace($place))
            ->postJson('/api/v1/redemptions/verify', ['code' => $code, 'place_id' => $place->id])
            ->assertOk();
        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'wrong scan');

        // The badge follows the counter in BOTH directions rather than being a
        // one-way retirement.
        expect(mapPinFor($place)['has_active_offer'])->toBeTrue();
    });
});

describe('the exhausted-offer fixture', function () {
    /*
     * `OfferFactory::quotaExhausted()` is why the missing maintenance stayed
     * green for a phase: it hand-set the column on a state that was only ever
     * `->make()`n, so the rule was tested and the maintenance was not. It keeps
     * working — a test that genuinely wants an exhausted offer should not have
     * to issue fifty codes — but a CREATED one now has the rows to back the
     * number, so the state it fabricates is one the pipeline could have reached.
     */
    it('creates an offer whose exhaustion is real rows, not just a number', function () {
        $offer = Offer::factory()->quotaExhausted(3)->create(['place_id' => Place::factory()->active()]);

        expect($offer->redemptions_count)->toBe(3)
            ->and(Redemption::query()->where('offer_id', $offer->id)->count())->toBe(3)
            // Counter and rows agree, so the nightly reconciler has nothing to
            // say about a database seeded with this fixture.
            ->and(app(OfferQuotaReconciler::class)->reconcile()->isHealthy())->toBeTrue();

        issueAsNewDiner($offer)
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'offer_not_redeemable');
    });
});
