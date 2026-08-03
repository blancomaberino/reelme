<?php

use App\Enums\RedemptionStatus;
use App\Events\RedemptionVerified;
use App\Listeners\NotifyOnRedemptionVerified;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\User;
use App\Services\Redemptions\RedemptionIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        $offer = Offer::factory()->active()->create(['place_id' => Place::factory()->active()]);
        $overdue = Redemption::factory()->overdue()->create(['offer_id' => $offer->id]);
        $live = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);

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
        $offer = Offer::factory()->active()->create(['place_id' => Place::factory()->active()]);
        $redeemed = Redemption::factory()->redeemed()->create([
            'offer_id' => $offer->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('reelmap:redemptions:expire')->assertSuccessful();

        expect($redeemed->fresh()->status)->toBe(RedemptionStatus::Redeemed);
    });

    it('is a no-op when nothing is overdue', function () {
        Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);

        $this->artisan('reelmap:redemptions:expire')
            ->expectsOutputToContain('Expired 0 redemption(s).')
            ->assertSuccessful();
    });

    it('frees the per-user slot it retires', function () {
        $offer = Offer::factory()->active()->create([
            'place_id' => Place::factory()->active(),
            'quota_per_user' => 1,
        ]);
        $diner = User::factory()->create();
        Redemption::factory()->overdue()->create(['offer_id' => $offer->id, 'user_id' => $diner->id]);

        $this->artisan('reelmap:redemptions:expire')->assertSuccessful();

        // An unvisited code never used the offer; the diner must not be locked
        // out of it because the restaurant was closed that evening.
        expect(app(RedemptionIssuer::class)->issue($offer, $diner)->status)
            ->toBe(RedemptionStatus::Issued);
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
        $offer = Offer::factory()->active()->create(['place_id' => Place::factory()->active()]);
        Redemption::factory()->create(['offer_id' => $offer->id]);

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
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);

        expect(fn () => DB::transaction(fn () => Redemption::query()->whereKey($redemption->id)->update([
            'status' => RedemptionStatus::Redeemed,
            // redeemed_at deliberately left null
        ])))->toThrow(QueryException::class);

        expect($redemption->fresh()->status)->toBe(RedemptionStatus::Issued);
    });

    it('refuses an issued row that carries a redeemed_at', function () {
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);

        expect(fn () => DB::transaction(fn () => Redemption::query()->whereKey($redemption->id)->update([
            'redeemed_at' => now(),
        ])))->toThrow(QueryException::class);

        expect($redemption->fresh()->redeemed_at)->toBeNull();
    });

    it('keeps the redemption when its attributed share is deleted', function () {
        $redemption = Redemption::factory()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
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
        $redemption = Redemption::factory()->redeemed()->create([
            'offer_id' => Offer::factory()->active()->create(['place_id' => Place::factory()->active()])->id,
        ]);
        // A hard-deleted diner leaves the money record standing (the FK on
        // `user_id` cascades, so this is the in-memory case: a relation that
        // resolves to null must not fatal the listener).
        $redemption->setRelation('user', null);

        Notification::fake();
        app(NotifyOnRedemptionVerified::class)->handle(new RedemptionVerified($redemption));

        Notification::assertNothingSent();
    });
});
