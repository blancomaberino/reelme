<?php

use App\Enums\LedgerAccount;
use App\Enums\PayoutStatus;
use App\Enums\RedemptionStatus;
use App\Events\InfluencerClaimed;
use App\Models\Influencer;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Payout;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\PlaceSource;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\FakeStripeConnect;
use App\Services\Payments\StripeConnect;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The M4 phase gate (ROADMAP §M4 exit criteria, 06 §2–§5).
 *
 * Every piece of this loop already has its own unit-level suite. This file
 * exists because those suites each mock the seam they sit next to, and the loop
 * is exactly where a mock and reality disagree: T-043 proves a code verifies,
 * T-044 proves a posting balances, T-045 proves a transfer is created — and none
 * of them proves that the euro a diner triggered is the euro that lands in an
 * influencer's payout, or that the dashboard reports the same number the wallet
 * pays.
 *
 * So it drives the REAL HTTP endpoints end to end (factories for setup only, per
 * the task) with a fake Stripe and no network, and asserts the invariant that
 * ties the whole phase together: **sum(debits) = sum(credits), per currency,
 * after every step.**
 */
const LOOP_LAT = 38.7223;
const LOOP_LNG = -9.1393;

function loopStripe(): FakeStripeConnect
{
    /** @var FakeStripeConnect $fake */
    $fake = app(StripeConnect::class);

    return $fake;
}

/**
 * The whole cast: a published place carrying an influencer's post, the operator
 * who can verify at the till, a live offer, and a diner.
 *
 * @return array{place: Place, offer: Offer, operator: User, influencer: Influencer, share: Share, diner: User}
 */
function loopScenario(?User $influencerOwner = null): array
{
    $place = Place::factory()->active()->atPoint(LOOP_LAT, LOOP_LNG)->create();

    $influencer = Influencer::factory()->create(['claimed_by_user_id' => $influencerOwner?->id]);
    $sourcePost = SourcePost::factory()->create(['influencer_id' => $influencer->id]);
    $share = Share::factory()->create();
    PlaceSource::factory()->primary()->create([
        'place_id' => $place->id,
        'source_post_id' => $sourcePost->id,
        'share_id' => $share->id,
    ]);

    $operator = User::factory()->create(['is_restaurant_owner' => true]);
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    return [
        'place' => $place,
        'offer' => Offer::factory()->active()->create(['place_id' => $place->id]),
        'operator' => $operator,
        'influencer' => $influencer,
        'share' => $share,
        'diner' => User::factory()->create(),
    ];
}

/**
 * The global invariant, asserted after every step rather than once at the end.
 *
 * Checking only at the end would still catch an unbalanced total, but it would
 * not tell you WHICH step broke it — and a double-entry bug that nets to zero
 * across two steps is precisely the one worth localising.
 */
function assertBooksBalance(string $after): void
{
    $rows = DB::table('ledger_entries')
        ->groupBy('currency')
        ->select('currency')
        ->selectRaw("coalesce(sum(amount) FILTER (WHERE direction = 'debit'), 0) AS debits")
        ->selectRaw("coalesce(sum(amount) FILTER (WHERE direction = 'credit'), 0) AS credits")
        ->get();

    foreach ($rows as $row) {
        expect((int) $row->debits)->toBe(
            (int) $row->credits,
            "Books do not balance in {$row->currency} after {$after}: {$row->debits} debits vs {$row->credits} credits.",
        );
    }
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-06 12:00:00');
    // NOTE on T-043's velocity limiters: they are keyed PER DINER
    // ('redemption:issue:day:{id}' and ':week:{id}'), so clearing a guessed
    // prefix does nothing — this file previously did exactly that, which is
    // decorative code pretending to be a safeguard. What actually keeps the
    // 3/day cap out of the way is that every test takes a fresh diner from
    // loopScenario(). A test that issues 4+ codes to the SAME diner must clear
    // those two real keys itself, or it fails as "fraud" and reads as a bug.
    // Must be the secret `postWebhook()` signs with, or every event is rejected
    // for a bad signature — which surfaces as "the payout never reached paid"
    // rather than as anything to do with signing.
    config()->set('services.stripe.webhook_secret', WEBHOOK_SECRET);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('runs the full offer → redeem → verify → ledger → payout loop', function () {
    $s = loopScenario($owner = User::factory()->create(['is_influencer' => true]));
    $fee = (int) config('monetization.redemption_fee_minor');
    $bps = (int) $s['offer']->influencer_share_bps;
    $influencerShare = intdiv($fee * $bps, 10_000);

    // --- 1. The diner takes the offer from the influencer's post -------------
    $issued = $this->actingAs($s['diner'])
        ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
        ->assertCreated()
        ->json('data');

    $redemption = Redemption::query()->findOrFail($issued['id']);

    // Attribution is FROZEN here, not recomputed at payout time — the share
    // could be edited or deleted before the diner ever walks in.
    expect($redemption->attributed_influencer_id)->toBe($s['influencer']->id)
        ->and($redemption->attributed_share_id)->toBe($s['share']->id)
        ->and($issued['status'])->toBe('issued');

    // Issuing costs nobody anything: only a REDEEMED code is billable (06 §2.3).
    expect(LedgerEntry::query()->count())->toBe(0);

    // --- 2. The restaurant verifies at the till ------------------------------
    $this->actingAs($s['operator'])
        ->postJson('/api/v1/redemptions/verify', [
            'code' => $issued['code'],
            'place_id' => $s['place']->id,
            'lat' => LOOP_LAT,
            'lng' => LOOP_LNG,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', RedemptionStatus::Redeemed->value);

    assertBooksBalance('verify');

    // --- 3. …which is what creates the money ---------------------------------
    $entries = LedgerEntry::query()->where('reference_type', 'redemption')->where('reference_id', $redemption->id)->get();

    // ONE transaction group, so the fee and its split can never half-commit.
    expect($entries->pluck('transaction_uuid')->unique())->toHaveCount(1);

    $ledger = app(LedgerService::class);
    expect($ledger->balance(LedgerAccount::RestaurantReceivable))->toBe($fee)
        ->and($ledger->balance(LedgerAccount::InfluencerEarnings, $owner))->toBe($influencerShare)
        // The platform keeps the remainder cent — intdiv, not round.
        ->and($ledger->balance(LedgerAccount::PlatformRevenue))->toBe($fee - $influencerShare);

    // --- 4. The influencer sees it in their wallet ---------------------------
    $this->actingAs($owner)
        ->getJson('/api/v1/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance.available.amount', $influencerShare);

    // --- 5. …and can cash it out ---------------------------------------------
    // Below the €25 threshold one redemption never pays out, and the point here
    // is the transfer mechanics, not the threshold (PayoutTest owns that).
    config()->set('monetization.payout_threshold_minor', 1);
    loopStripe()->enablePayouts($owner);

    $payoutId = $this->actingAs($owner)
        ->postJson('/api/v1/wallet/payouts')
        ->assertCreated()
        ->json('data.id');

    $payout = Payout::query()->findOrFail($payoutId);

    expect($payout->status)->toBe(PayoutStatus::Processing)
        ->and($payout->amount)->toBe($influencerShare)
        ->and($payout->stripe_transfer_id)->not->toBeNull();

    // The hold has already left the payable balance, so a second request cannot
    // spend the same euros while the first is in flight.
    expect($ledger->balance(LedgerAccount::InfluencerEarnings, $owner))->toBe(0);
    assertBooksBalance('payout request');

    // --- 6. Stripe confirms ---------------------------------------------------
    // Asserted, not fired and forgotten: a rejected event returns non-2xx and
    // would otherwise show up three lines later as a mysterious status.
    postWebhook('transfer.paid', ['id' => $payout->stripe_transfer_id], id: 'evt_m4_loop')->assertOk();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid);
    assertBooksBalance('transfer.paid');

    // --- 7. The dashboard tells the same story --------------------------------
    $dashboard = $this->actingAs($owner)
        ->getJson('/api/v1/me/influencer/dashboard?period=all')
        ->assertOk()
        ->json('data');

    expect($dashboard['funnel']['issued'])->toBe(1)
        ->and($dashboard['funnel']['redeemed'])->toBe(1)
        // Earnings are what was EARNED, so a completed payout must not erase
        // them — the funnel would otherwise reset to zero the moment you cash out.
        ->and($dashboard['funnel']['earnings']['amount'])->toBe($influencerShare)
        ->and($dashboard['by_place'])->toHaveCount(1)
        ->and($dashboard['by_place'][0]['place']['slug'])->toBe($s['place']->slug)
        ->and($dashboard['by_place'][0]['redeemed'])->toBe(1);
});

it('keeps the books balanced under the ledger:verify command', function () {
    $s = loopScenario($owner = User::factory()->create(['is_influencer' => true]));

    $issued = $this->actingAs($s['diner'])
        ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
        ->assertCreated()
        ->json('data');

    $this->actingAs($s['operator'])
        ->postJson('/api/v1/redemptions/verify', [
            'code' => $issued['code'],
            'place_id' => $s['place']->id,
            'lat' => LOOP_LAT,
            'lng' => LOOP_LNG,
        ])
        ->assertOk();

    // The same invariant the nightly job enforces, over the loop's own data.
    $this->artisan('reelmap:ledger:verify')->assertSuccessful();
});

describe('the fraud rejections M4 exits on', function () {
    it('refuses a second verify of the same code, and bills it once', function () {
        $s = loopScenario(User::factory()->create(['is_influencer' => true]));

        $issued = $this->actingAs($s['diner'])
            ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
            ->assertCreated()->json('data');

        $verify = fn () => $this->actingAs($s['operator'])->postJson('/api/v1/redemptions/verify', [
            'code' => $issued['code'],
            'place_id' => $s['place']->id,
            'lat' => LOOP_LAT,
            'lng' => LOOP_LNG,
        ]);

        $verify()->assertOk();
        // Idempotent on the code: the till re-scanning must not be an error the
        // operator has to argue about — but it must not bill twice either.
        $verify()->assertOk()->assertJsonPath('meta.replayed', true);

        // Cast: Postgres sums come back as strings through PDO, and `toBe` is
        // identical-comparison, so '300' would fail against 300.
        expect((int) LedgerEntry::query()->where('account', LedgerAccount::RestaurantReceivable)->sum('amount'))
            ->toBe((int) config('monetization.redemption_fee_minor'));
        assertBooksBalance('double verify');
    });

    it('refuses an expired code and charges nothing', function () {
        $s = loopScenario(User::factory()->create(['is_influencer' => true]));

        $issued = $this->actingAs($s['diner'])
            ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
            ->assertCreated()->json('data');

        Carbon::setTestNow(now()->addDays(2));

        $this->actingAs($s['operator'])
            ->postJson('/api/v1/redemptions/verify', [
                'code' => $issued['code'],
                'place_id' => $s['place']->id,
                'lat' => LOOP_LAT,
                'lng' => LOOP_LNG,
            ])
            ->assertStatus(422);

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses a code presented at another restaurant', function () {
        $s = loopScenario(User::factory()->create(['is_influencer' => true]));
        $other = loopScenario();

        $issued = $this->actingAs($s['diner'])
            ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
            ->assertCreated()->json('data');

        // A valid code at the wrong venue is the cheapest fraud there is: the
        // fee must follow the offer's place, never the scanner's.
        $this->actingAs($other['operator'])
            ->postJson('/api/v1/redemptions/verify', [
                'code' => $issued['code'],
                'place_id' => $other['place']->id,
                'lat' => LOOP_LAT,
                'lng' => LOOP_LNG,
            ])
            ->assertStatus(422);

        expect(LedgerEntry::query()->count())->toBe(0);
    });
});

it('accrues an unclaimed influencer’s share to escrow, then releases it on claim', function () {
    // Nobody owns this identity yet, so the money is owed to a person we cannot
    // name (06 §5.3). It must not land in anyone's wallet in the meantime.
    $s = loopScenario(influencerOwner: null);

    $issued = $this->actingAs($s['diner'])
        ->postJson('/api/v1/redemptions', ['offer_id' => $s['offer']->id, 'share_id' => $s['share']->id])
        ->assertCreated()->json('data');

    $this->actingAs($s['operator'])
        ->postJson('/api/v1/redemptions/verify', [
            'code' => $issued['code'],
            'place_id' => $s['place']->id,
            'lat' => LOOP_LAT,
            'lng' => LOOP_LNG,
        ])
        ->assertOk();

    $ledger = app(LedgerService::class);
    $fee = (int) config('monetization.redemption_fee_minor');
    $share = intdiv($fee * (int) $s['offer']->influencer_share_bps, 10_000);

    expect($ledger->escrowBalance($s['influencer']))->toBe($share)
        // `balance(account, null)` is "rows with no user" — i.e. escrow — and
        // that is the whole point: it belongs to nobody's wallet yet.
        ->and($ledger->balance(LedgerAccount::InfluencerEarnings, null))->toBe($share);
    assertBooksBalance('escrow accrual');

    // --- the claim --------------------------------------------------------
    $claimant = User::factory()->create(['is_influencer' => true]);
    // Persist the claim BEFORE firing, which is the order InfluencerClaimService
    // uses. Firing against a still-unclaimed row would let this pass even if the
    // listener depended on the claim already being committed.
    $s['influencer']->forceFill(['claimed_by_user_id' => $claimant->id])->save();
    event(new InfluencerClaimed($s['influencer']->refresh(), $claimant));

    expect($ledger->balance(LedgerAccount::InfluencerEarnings, $claimant))->toBe($share)
        ->and($ledger->escrowBalance($s['influencer']))->toBe(0);
    assertBooksBalance('escrow release');

    // The release must NOT read as new earnings: it references the influencer,
    // not a redemption, so the funnel still shows the one visit that earned it.
    $dashboard = $this->actingAs($claimant)
        ->getJson('/api/v1/me/influencer/dashboard?period=all')
        ->assertOk()
        ->json('data');

    expect($dashboard['funnel']['redeemed'])->toBe(1)
        ->and($dashboard['funnel']['earnings']['amount'])->toBe($share)
        ->and($dashboard['money']['available']['amount'])->toBe($share);
});
