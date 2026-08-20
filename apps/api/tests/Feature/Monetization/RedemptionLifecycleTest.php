<?php

use App\Enums\RedemptionStatus;
use App\Events\RedemptionVerified;
use App\Listeners\NotifyOnRedemptionVerified;
use App\Models\Offer;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\User;
use App\Services\Redemptions\RedemptionIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The row's lifecycle beyond issue and verify (T-043).
 *
 * These are the invariants that hold when nobody is looking: the sweep that
 * retires unused codes (06 §2.3 — only a redeemed one is billable), and the
 * database-level guarantees that keep a billable row honest even against a
 * direct write.
 */
describe('the expiry sweep', function () {
    it('retires codes whose window closed and leaves live ones alone', function () {
        $offer = activeOfferAt();
        $overdue = Redemption::factory()->overdue()->holdingSlot()->create(['offer_id' => $offer->id]);
        $live = Redemption::factory()->holdingSlot()->create(['offer_id' => activeOfferAt()->id]);

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 1 redemption(s).')
            ->assertSuccessful();

        expect($overdue->fresh()->status)->toBe(RedemptionStatus::Expired)
            ->and($live->fresh()->status)->toBe(RedemptionStatus::Issued);
    });

    /*
     * A code redeemed between the sweep's read and its write must NOT be
     * flipped to expired — a restaurant billed for a visit whose row then
     * denies it happened is the worst possible reconciliation bug.
     */
    it('never retires a code that was redeemed in the meantime', function () {
        $offer = activeOfferAt();
        $redeemed = Redemption::factory()->redeemed()->holdingSlot()->create([
            'offer_id' => $offer->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('reelmap:redemptions:expire')->assertSuccessful();

        // Redeemed still holds its slot: the restaurant is billed for that
        // visit, so handing the quota back would buy a free extra dessert.
        expect($redeemed->fresh()->status)->toBe(RedemptionStatus::Redeemed)
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    it('is a no-op when nothing is overdue', function () {
        $offer = activeOfferAt();
        Redemption::factory()->holdingSlot()->create(['offer_id' => $offer->id]);

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 0 redemption(s).')
            ->assertSuccessful();

        expect($offer->refresh()->redemptions_count)->toBe(1);
    });

    it('frees the per-user slot it retires', function () {
        $offer = activeOfferAt(attributes: ['quota_per_user' => 1]);
        $diner = User::factory()->create();
        Redemption::factory()->overdue()->holdingSlot()->create(['offer_id' => $offer->id, 'user_id' => $diner->id]);

        $this->artisan('reelmap:redemptions:expire')->assertSuccessful();

        // An unvisited code never used the offer; the diner must not be locked
        // out of it because the restaurant was closed that evening.
        expect(app(RedemptionIssuer::class)->issue($offer, $diner)->status)
            ->toBe(RedemptionStatus::Issued)
            // And the issue took a fresh slot against the one the sweep gave
            // back, rather than a second one on top of it.
            ->and($offer->refresh()->redemptions_count)->toBe(1);
    });

    /*
     * The path a real sweep takes, asserted as a path rather than as an outcome.
     *
     * `release()` has two branches and they end at the same counter value when
     * the offer starts at zero: the ordinary decrement, and the refusal that
     * logs `offer.quota_counter_drift` and writes nothing. Which one a sweep
     * test takes is decided entirely by whether its fixtures hold the slots
     * their rows stand for (`RedemptionFactory::holdingSlot()`), and every
     * test in this file once took the refusal one. The alert is the whole reason
     * the drift branch exists — a regression that made it fire on every hourly
     * sweep in production would be exactly this state, and nothing here would
     * have said a word.
     */
    it('returns the slots through the ordinary release, without reporting drift', function () {
        $offer = activeOfferAt();
        Redemption::factory()->count(2)->overdue()->holdingSlot()->create(['offer_id' => $offer->id]);
        expect($offer->refresh()->redemptions_count)->toBe(2);

        Log::spy();

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 2 redemption(s).')
            ->assertSuccessful();

        expect($offer->refresh()->redemptions_count)->toBe(0);

        Log::shouldNotHaveReceived('warning', ['offer.quota_counter_drift', Mockery::any()]);
    });
});

describe('database-level guarantees', function () {
    /*
     * FK RESTRICT: an offer with redemptions against it cannot be deleted.
     * T-042 already archives rather than deletes, but a fee charged against a
     * vanished offer cannot be audited, so the rule must hold for a direct
     * write too.
     */
    it('refuses to delete an offer that has redemptions', function () {
        $offer = activeOfferAt();
        Redemption::factory()->holdingSlot()->create(['offer_id' => $offer->id]);

        // Wrapped in a nested transaction so the violation rolls back to a
        // SAVEPOINT: in Postgres a failed statement aborts the whole
        // transaction, and RefreshDatabase is already inside one — without this
        // every query after the expected failure errors too.
        expect(fn () => DB::transaction(fn () => $offer->delete()))->toThrow(QueryException::class);

        expect(Offer::query()->whereKey($offer->id)->exists())->toBeTrue();
    });

    /*
     * The CHECK keeps `status = redeemed` and `redeemed_at` in step. Without it
     * a partial write leaves a row that is billable but cannot say when it was
     * honoured — unanswerable in a dispute.
     */
    it('refuses a redeemed row with no redeemed_at', function () {
        $redemption = Redemption::factory()->holdingSlot()->create(['offer_id' => activeOfferAt()->id]);

        expect(fn () => DB::transaction(fn () => Redemption::query()->whereKey($redemption->id)->update([
            'status' => RedemptionStatus::Redeemed,
            // redeemed_at deliberately left null
        ])))->toThrow(QueryException::class);

        expect($redemption->fresh()->status)->toBe(RedemptionStatus::Issued);
    });

    it('refuses an issued row that carries a redeemed_at', function () {
        $redemption = Redemption::factory()->holdingSlot()->create(['offer_id' => activeOfferAt()->id]);

        expect(fn () => DB::transaction(fn () => Redemption::query()->whereKey($redemption->id)->update([
            'redeemed_at' => now(),
        ])))->toThrow(QueryException::class);

        expect($redemption->fresh()->redeemed_at)->toBeNull();
    });

    it('keeps the redemption when its attributed share is deleted', function () {
        $redemption = Redemption::factory()->holdingSlot()->create([
            'offer_id' => activeOfferAt()->id,
            'attributed_share_id' => Share::factory(),
        ]);

        $redemption->attributedShare->delete();

        // SET NULL, not cascade: the money record outlives the share (02 §3.14).
        expect(Redemption::query()->whereKey($redemption->id)->exists())->toBeTrue()
            ->and($redemption->fresh()->attributed_share_id)->toBeNull();
    });
});

describe('the quota-slot rule', function () {
    /*
     * One definition, asked by three callers (the offer's per-day cap, the
     * diner's per-user cap, the counter cache). Pinned as its own fact so a
     * future state — a refund's `void` (06 §4.4) — has to be classified here
     * rather than in whichever query someone edits first.
     */
    it('counts issued and redeemed as holding a slot, and nothing else', function (RedemptionStatus $status, bool $holds) {
        expect($status->holdsQuota())->toBe($holds);
    })->with([
        [RedemptionStatus::Issued, true],
        [RedemptionStatus::Redeemed, true],
        // An unused code never used the offer; a voided one gave its slot back.
        [RedemptionStatus::Expired, false],
        [RedemptionStatus::Void, false],
    ]);

    it('exposes the same set as a query-builder list', function () {
        expect(RedemptionStatus::holdingQuota())->toBe([RedemptionStatus::Issued, RedemptionStatus::Redeemed]);
    });
});

describe('the verified-notification listener', function () {
    it('does nothing when the diner account is gone', function () {
        $redemption = Redemption::factory()->redeemed()->holdingSlot()->create(['offer_id' => activeOfferAt()->id]);
        // A hard-deleted diner leaves the money record standing (the FK on
        // `user_id` cascades, so this is the in-memory case: a relation that
        // resolves to null must not fatal the listener).
        $redemption->setRelation('user', null);

        Notification::fake();
        app(NotifyOnRedemptionVerified::class)->handle(new RedemptionVerified($redemption));

        Notification::assertNothingSent();
    });
});
