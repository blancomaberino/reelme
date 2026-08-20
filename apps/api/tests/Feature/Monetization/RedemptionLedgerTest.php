<?php

use App\Enums\LedgerAccount;
use App\Enums\RedemptionStatus;
use App\Events\InfluencerClaimed;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Influencers\InfluencerClaimService;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\RedemptionVoider;
use App\Services\Redemptions\OfferQuotaCounter;
use App\Services\Redemptions\RedemptionVerifier;
use Illuminate\Support\Facades\DB;

/**
 * A verified redemption becomes money (T-044, 06 §4.1–4.2).
 *
 * The organising property: **the fee and the redemption commit together or
 * neither does.** T-043 dispatches `RedemptionVerified` inside its verify
 * transaction and this listener is deliberately NOT queued, so the two failure
 * modes that matter — a redeemed row with no fee (a free meal) and a fee for a
 * redemption that rolled back (money invented) — are both unreachable.
 */
function venueAndOperator(): array
{
    $place = Place::factory()->active()->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    return [$place, $operator];
}

function codeFor(Place $place, ?Influencer $influencer = null, array $offerAttributes = []): Redemption
{
    // `holdingSlot()`, because the void tests below are about `release()`: a row
    // seeded without the slot it holds sends them down the drift branch instead
    // of the ordinary one (see RedemptionFactory's docblock).
    return Redemption::factory()->withCode('ABCD1234EF')->holdingSlot()->create([
        'offer_id' => activeOfferAt($place, $offerAttributes)->id,
        'attributed_influencer_id' => $influencer?->id,
    ]);
}

function verifyCode(User $operator, Place $place): void
{
    app(RedemptionVerifier::class)->verify($operator, 'ABCD1234EF', $place);
}

describe('the posting', function () {
    it('splits the fee between the platform and the influencer', function () {
        [$place, $operator] = venueAndOperator();
        $earner = User::factory()->create();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => $earner->id]);
        // 5000 bps = 50%, the v1 business default (06 §4.1).
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);

        verifyCode($operator, $place);

        expect(LedgerEntry::query()->count())->toBe(3)
            // The venue owes the whole fee; it is split on the credit side.
            ->and(app(LedgerService::class)->balance(LedgerAccount::RestaurantReceivable))->toBe(300)
            ->and(app(LedgerService::class)->balance(LedgerAccount::PlatformRevenue))->toBe(150)
            ->and(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings, $earner))->toBe(150);
    });

    it('prices the redemption at redemption time, not at issue', function () {
        [$place, $operator] = venueAndOperator();
        $redemption = codeFor($place);

        // T-043 leaves both null precisely so an offer repriced while the code
        // sat in a pocket bills the rate in force when the diner walked in.
        expect($redemption->fee_amount)->toBeNull()->and($redemption->currency)->toBeNull();

        verifyCode($operator, $place);

        expect($redemption->fresh()->fee_amount)->toBe(300)
            ->and($redemption->fresh()->currency)->toBe('EUR');
    });

    it('honours the share frozen on the offer, not the current config', function () {
        [$place, $operator] = venueAndOperator();
        $earner = User::factory()->create();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => $earner->id]);
        // A campaign negotiated at 25% — 06 §4.1: changes are never retroactive.
        codeFor($place, $influencer, ['influencer_share_bps' => 2500]);

        verifyCode($operator, $place);

        expect(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings, $earner))->toBe(75)
            ->and(app(LedgerService::class)->balance(LedgerAccount::PlatformRevenue))->toBe(225);
    });

    /*
     * The remainder cent goes to the platform. Rounding the other way would pay
     * out more than was collected, and the transaction would not balance —
     * these two credits are the debit's only counterparties.
     */
    it('keeps the split exact when it does not divide evenly', function () {
        config()->set('monetization.redemption_fee_minor', 333);
        [$place, $operator] = venueAndOperator();
        $earner = User::factory()->create();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => $earner->id]);
        codeFor($place, $influencer, ['influencer_share_bps' => 3333]);

        verifyCode($operator, $place);

        $ledger = app(LedgerService::class);
        $share = $ledger->balance(LedgerAccount::InfluencerEarnings, $earner);
        $platform = $ledger->balance(LedgerAccount::PlatformRevenue);

        expect($share)->toBe(110)
            ->and($platform)->toBe(223)
            ->and($share + $platform)->toBe(333);
    });

    it('refuses to post when the configured fee is outside the 06 §2.3 band', function () {
        // A typo in an env var would otherwise bill every restaurant wrongly and
        // look exactly like normal operation.
        config()->set('monetization.redemption_fee_minor', 9999);
        [$place, $operator] = venueAndOperator();
        codeFor($place);

        expect(fn () => verifyCode($operator, $place))->toThrow(RuntimeException::class);

        // The verify rolls back with it — nothing half-happened.
        expect(Redemption::firstOrFail()->status)->toBe(RedemptionStatus::Issued)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});

describe('atomicity with the redemption', function () {
    /*
     * The whole reason the listener is not queued. If the caller's transaction
     * rolls back, the fee must go with it — otherwise the books say a
     * restaurant owes €3 for a visit that, according to the redemption, never
     * happened.
     */
    it('rolls the fee back with the redemption', function () {
        [$place, $operator] = venueAndOperator();
        codeFor($place);

        try {
            DB::transaction(function () use ($operator, $place) {
                verifyCode($operator, $place);

                throw new RuntimeException('caller failed after the verify');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(Redemption::firstOrFail()->status)->toBe(RedemptionStatus::Issued)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('posts the fee exactly once across a replayed verify', function () {
        [$place, $operator] = venueAndOperator();
        codeFor($place);

        verifyCode($operator, $place);
        verifyCode($operator, $place);

        expect(LedgerEntry::query()->count())->toBe(3)
            ->and(app(LedgerService::class)->balance(LedgerAccount::RestaurantReceivable))->toBe(300);
    });
});

describe('escrow for an unclaimed influencer (06 §5.3)', function () {
    it('accrues the share with no user when nobody has claimed the identity', function () {
        [$place, $operator] = venueAndOperator();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => null]);
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);

        verifyCode($operator, $place);

        $ledger = app(LedgerService::class);
        // Owed, but to nobody in particular yet — traceable to the identity only
        // through the redemption reference.
        expect($ledger->escrowBalance($influencer))->toBe(150)
            ->and($ledger->balance(LedgerAccount::InfluencerEarnings))->toBe(150);

        expect(LedgerEntry::query()->escrow()->count())->toBe(1);
    });

    it('moves the whole escrow balance to the claimant on a verified claim', function () {
        [$place, $operator] = venueAndOperator();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => null]);
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);
        verifyCode($operator, $place);

        $claimant = User::factory()->create();
        InfluencerClaimed::dispatch($influencer->fresh(), $claimant);

        $ledger = app(LedgerService::class);
        expect($ledger->balance(LedgerAccount::InfluencerEarnings, $claimant))->toBe(150)
            // Escrow is emptied by a TRANSFER, not by rewriting the original
            // credit — the books still show it accrued before it was released.
            ->and($ledger->escrowBalance($influencer))->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(5);
    });

    it('is a no-op when the claimed identity earned nothing', function () {
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => null]);
        $claimant = User::factory()->create();

        InfluencerClaimed::dispatch($influencer, $claimant);

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('releases the escrow only once across a repeated claim event', function () {
        [$place, $operator] = venueAndOperator();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => null]);
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);
        verifyCode($operator, $place);

        $claimant = User::factory()->create();
        InfluencerClaimed::dispatch($influencer->fresh(), $claimant);
        InfluencerClaimed::dispatch($influencer->fresh(), $claimant);

        expect(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings, $claimant))->toBe(150)
            ->and(LedgerEntry::query()->count())->toBe(5);
    });

    it('runs for real through the claim service, not only the event', function () {
        [$place, $operator] = venueAndOperator();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => null]);
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);
        verifyCode($operator, $place);

        // The path T-038 actually takes when an admin approves a claim.
        $claimant = User::factory()->create();
        $claim = InfluencerClaim::factory()->create([
            'influencer_id' => $influencer->id,
            'user_id' => $claimant->id,
        ]);
        app(InfluencerClaimService::class)
            ->approve($claim, User::factory()->admin()->create());

        expect(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings, $claimant))->toBe(150);
    });
});

describe('voiding a disputed redemption (06 §4.4)', function () {
    it('reverses the posting rather than deleting it', function () {
        [$place, $operator] = venueAndOperator();
        $earner = User::factory()->create();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => $earner->id]);
        codeFor($place, $influencer, ['influencer_share_bps' => 5000]);
        verifyCode($operator, $place);

        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'wrong scan');

        $ledger = app(LedgerService::class);
        expect($ledger->balance(LedgerAccount::RestaurantReceivable))->toBe(0)
            ->and($ledger->balance(LedgerAccount::PlatformRevenue))->toBe(0)
            ->and($ledger->balance(LedgerAccount::InfluencerEarnings, $earner))->toBe(0)
            // Both sets survive: a restaurant asking "why was this on my
            // invoice" needs to see the charge AND the reversal.
            ->and(LedgerEntry::query()->count())->toBe(6);

        expect(Redemption::firstOrFail()->status)->toBe(RedemptionStatus::Void);
    });

    it('keeps fee_amount as the record of what was charged', function () {
        [$place, $operator] = venueAndOperator();
        codeFor($place);
        verifyCode($operator, $place);

        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'no-show');

        // Blanking it would erase the fact a fee ever applied; the reversal is
        // what records that it was given back.
        expect(Redemption::firstOrFail()->fee_amount)->toBe(300);
    });

    it('refuses to void a redemption that was never redeemed', function () {
        [$place] = venueAndOperator();
        $redemption = codeFor($place);

        expect(fn () => app(RedemptionVoider::class)->void($redemption, 'mistake'))
            ->toThrow(RuntimeException::class);
    });

    /*
     * Two admins on the same dispute, or one admin whose request was retried.
     *
     * The check at the top of `void()` reads a model loaded OUTSIDE the
     * transaction, so a stale instance sails straight past it — which is why the
     * real guard is the UPDATE's own `where status = redeemed`. The ledger
     * survived a double void on its own (`reverse()` is idempotent by key), and
     * that is exactly what made this hard to see: the books came out right while
     * the COUNTER was handed the same slot back twice, leaving the offer able to
     * serve one more free dessert than it ever sold.
     *
     * Two slots held, one of them disputed, so the arithmetic distinguishes the
     * two outcomes: 1 is correct, 0 is the bug. With a single slot both land on
     * 0 — the second release would be refused by the counter's own floor and log
     * drift instead — and the test would pass either way.
     */
    it('refuses a second void of the same redemption, and gives the slot back once', function () {
        [$place, $operator] = venueAndOperator();
        $offer = Offer::factory()->active()->create(['place_id' => $place->id, 'quota_total' => 2]);
        $counter = app(OfferQuotaCounter::class);

        $disputed = Redemption::factory()->withCode('ABCD1234EF')->create(['offer_id' => $offer->id]);
        expect($counter->claim($offer->id))->toBeTrue();
        Redemption::factory()->withCode('ZZZZ9999ZZ')->create(['offer_id' => $offer->id]);
        expect($counter->claim($offer->id))->toBeTrue();

        $verifier = app(RedemptionVerifier::class);
        $verifier->verify($operator, 'ABCD1234EF', $place);
        $verifier->verify($operator, 'ZZZZ9999ZZ', $place);

        expect($offer->refresh()->redemptions_count)->toBe(2)
            ->and(LedgerEntry::query()->count())->toBe(6);

        // Read before the void that wins: the instance the losing request is
        // still holding, which believes the row is `redeemed`.
        $stale = Redemption::query()->findOrFail($disputed->id);

        app(RedemptionVoider::class)->void(Redemption::query()->findOrFail($disputed->id), 'wrong scan');

        expect($offer->refresh()->redemptions_count)->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(9);

        expect($stale->status)->toBe(RedemptionStatus::Redeemed)
            ->and(fn () => app(RedemptionVoider::class)->void($stale, 'wrong scan'))
            ->toThrow(RuntimeException::class, "Redemption #{$disputed->id} was voided concurrently");

        // The losing transaction unwound whole: no second slot returned, no
        // second reversal posted, and one voided row rather than a row voided
        // twice. The winner's work stands untouched.
        expect($offer->refresh()->redemptions_count)->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(9)
            ->and(Redemption::query()->where('status', RedemptionStatus::Void)->count())->toBe(1)
            ->and($disputed->refresh()->status)->toBe(RedemptionStatus::Void);
    });

    /*
     * 06 §4.4: if the influencer was already paid, the negative balance is
     * carried against future earnings — no clawback transfer in v1. That falls
     * out of reversal for free, and is worth pinning because it looks like a
     * bug the first time someone sees a negative payable.
     */
    it('leaves a negative payable when the earner had already been credited', function () {
        [$place, $operator] = venueAndOperator();
        $earner = User::factory()->create();
        $influencer = Influencer::factory()->create(['claimed_by_user_id' => $earner->id]);
        codeFor($place, $influencer, ['influencer_share_bps' => 10000]);
        verifyCode($operator, $place);

        // Simulate the payout already having swept the balance.
        app(LedgerService::class)->record('payout:1:transfer', [
            LedgerLine::debit(LedgerAccount::InfluencerEarnings, 300, 'EUR', userId: $earner->id),
            LedgerLine::credit(LedgerAccount::PayoutClearing, 300, 'EUR'),
        ]);

        app(RedemptionVoider::class)->void(Redemption::firstOrFail(), 'disputed');

        expect(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings, $earner))->toBe(-300);
    });
});
