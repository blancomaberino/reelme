<?php

use App\Enums\RedemptionStatus;
use App\Models\Influencer;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Redemptions\RedemptionVerifier;
use Illuminate\Support\Carbon;

/**
 * The influencer funnel endpoint (T-048, 06 §5.2).
 *
 * The claim this endpoint makes is "these posts drove these paid visits and
 * earned you this much", so the tests are about the numbers being EXACT against
 * a deliberately messy dataset — expired and voided codes sitting next to real
 * ones, a second influencer's redemptions in the same table, and a place the
 * viewer never touched. A funnel that is merely non-zero would pass a much
 * weaker test and still be wrong on screen.
 */
function dashboardInfluencer(User $user): Influencer
{
    return Influencer::factory()->create(['claimed_by_user_id' => $user->id]);
}

function dashboardVenue(): array
{
    $place = Place::factory()->active()->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    return [$place, $operator];
}

/**
 * Issue a code at `$place` attributed to `$influencer`, then leave it in `$status`.
 *
 * Named for this file on purpose: Pest helper functions are GLOBAL across the
 * whole suite, so a generic `issueFor`/`venueWithOperator` collides with the
 * redemption tests' own helpers and the collision surfaces as a fatal in an
 * unrelated file.
 */
function dashboardCode(
    Place $place,
    ?Influencer $influencer,
    RedemptionStatus $status,
    User $operator,
    string $code,
    ?Carbon $at = null,
): Redemption {
    $offer = Offer::factory()->active()->create(['place_id' => $place->id]);

    $redemption = Redemption::factory()->withCode($code)->create([
        'offer_id' => $offer->id,
        'attributed_influencer_id' => $influencer?->id,
        'created_at' => $at ?? now(),
    ]);

    if ($status === RedemptionStatus::Redeemed) {
        // Through the real verifier so the ledger posting happens the way it
        // does in production — hand-writing the entries would let the dashboard
        // agree with a fiction.
        app(RedemptionVerifier::class)->verify($operator, $code, $place);
    } elseif ($status !== RedemptionStatus::Issued) {
        $redemption->forceFill(['status' => $status])->save();
    }

    return $redemption->refresh();
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-06 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('access', function () {
    it('403s a user with no claimed influencer identity', function () {
        $user = User::factory()->create(['is_influencer' => true]);

        $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard')->assertForbidden();
    });

    it('403s when the identity exists but nobody has claimed it', function () {
        // Escrow money belongs to the identity, not to a browsing user — this
        // is the door that keeps it that way.
        Influencer::factory()->create(['claimed_by_user_id' => null]);
        $user = User::factory()->create(['is_influencer' => true]);

        $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard')->assertForbidden();
    });

    it('401s a guest', function () {
        $this->getJson('/api/v1/me/influencer/dashboard')->assertUnauthorized();
    });
});

describe('the funnel', function () {
    it('counts issued and redeemed exactly, excluding expired and void', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');
        dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'BBBBBBBBBB');
        dashboardCode($place, $influencer, RedemptionStatus::Issued, $operator, 'CCCCCCCCCC');
        dashboardCode($place, $influencer, RedemptionStatus::Expired, $operator, 'DDDDDDDDDD');
        dashboardCode($place, $influencer, RedemptionStatus::Void, $operator, 'EEEEEEEEEE');

        $funnel = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.funnel');

        // All five codes really were handed out…
        expect($funnel['issued'])->toBe(5)
            // …but only the two honoured at the till are conversions. Counting
            // expired codes here would inflate every rate on the screen.
            ->and($funnel['redeemed'])->toBe(2);
    });

    it('never counts another influencer’s redemptions', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $mine = dashboardInfluencer($user);
        $theirs = Influencer::factory()->create(['claimed_by_user_id' => User::factory()->create()->id]);

        dashboardCode($place, $mine, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');
        dashboardCode($place, $theirs, RedemptionStatus::Redeemed, $operator, 'BBBBBBBBBB');
        dashboardCode($place, null, RedemptionStatus::Redeemed, $operator, 'CCCCCCCCCC');

        $funnel = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.funnel');

        expect($funnel['issued'])->toBe(1)->and($funnel['redeemed'])->toBe(1);
    });

    it('reports earnings as the influencer share of the fee, not the whole fee', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');

        $fee = (int) config('monetization.redemption_fee_minor');
        $bps = (int) Offer::query()->value('influencer_share_bps');

        $earnings = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.funnel.earnings');

        expect($earnings['amount'])->toBe(intdiv($fee * $bps, 10_000))
            ->and($earnings['currency'])->toBe(config('monetization.currency'));
    });

    it('says views are untracked rather than reporting zero', function () {
        $user = User::factory()->create(['is_influencer' => true]);
        dashboardInfluencer($user);

        $funnel = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.funnel');

        // `0` would be a claim that nobody looked; `null` is the truth, and
        // `views_tracked_since` is what tells a chart when to start believing it.
        expect($funnel['views'])->toBeNull()
            ->and($funnel['views_tracked_since'])->toBeNull();
    });
});

describe('the period window', function () {
    it('excludes redemptions older than the requested window', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($place, $influencer, RedemptionStatus::Issued, $operator, 'AAAAAAAAAA', now());
        dashboardCode($place, $influencer, RedemptionStatus::Issued, $operator, 'BBBBBBBBBB', now()->subDays(45));

        $recent = $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard?period=30d')->json('data.funnel.issued');
        $wider = $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard?period=90d')->json('data.funnel.issued');
        $all = $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard?period=all')->json('data.funnel.issued');

        expect($recent)->toBe(1)->and($wider)->toBe(2)->and($all)->toBe(2);
    });

    it('rejects a period it does not serve', function () {
        $user = User::factory()->create(['is_influencer' => true]);
        dashboardInfluencer($user);

        $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard?period=7d')
            ->assertStatus(422);
    });
});

describe('the breakdowns', function () {
    it('attributes earnings to the right place and orders by them', function () {
        [$busy, $operator] = dashboardVenue();
        [$quiet, $quietOperator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($busy, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');
        dashboardCode($busy, $influencer, RedemptionStatus::Redeemed, $operator, 'BBBBBBBBBB');
        dashboardCode($quiet, $influencer, RedemptionStatus::Redeemed, $quietOperator, 'CCCCCCCCCC');

        $byPlace = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.by_place');

        expect($byPlace)->toHaveCount(2)
            ->and($byPlace[0]['place']['slug'])->toBe($busy->slug)
            ->and($byPlace[0]['redeemed'])->toBe(2)
            ->and($byPlace[1]['place']['slug'])->toBe($quiet->slug)
            ->and($byPlace[1]['redeemed'])->toBe(1)
            // The per-place euros must sum to the headline, or the breakdown is
            // telling a different story from the number above it.
            ->and($byPlace[0]['earnings']['amount'] + $byPlace[1]['earnings']['amount'])
            ->toBe($this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard')->json('data.funnel.earnings.amount'));
    });

    it('keeps a per-post row whose share was deleted', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        $redemption = dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');
        // Attribution is frozen on the redemption, so deleting the share must
        // not delete the history of what it earned.
        $redemption->forceFill(['attributed_share_id' => null])->save();

        $bySource = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.by_source');

        expect($bySource)->toHaveCount(1)
            ->and($bySource[0]['post'])->toBeNull()
            ->and($bySource[0]['redeemed'])->toBe(1)
            ->and($bySource[0]['earnings']['amount'])->toBeGreaterThan(0);
    });

    it('caps top places at five', function () {
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        foreach (range(0, 5) as $i) {
            [$place, $operator] = dashboardVenue();
            dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, str_pad((string) $i, 10, 'Z'));
        }

        $body = $this->actingAs($user)->getJson('/api/v1/me/influencer/dashboard')->assertOk()->json('data');

        expect($body['top_places'])->toHaveCount(5)
            ->and($body['by_place'])->toHaveCount(6);
    });
});

describe('the money block', function () {
    it('reports the cashable balance and the payout threshold live', function () {
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');

        $money = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data.money');

        $fee = (int) config('monetization.redemption_fee_minor');
        $bps = (int) Offer::query()->value('influencer_share_bps');

        expect($money['available']['amount'])->toBe(intdiv($fee * $bps, 10_000))
            ->and($money['threshold']['amount'])->toBe((int) config('monetization.payout_threshold_minor'));
    });
});

describe('the contract', function () {
    it('answers in the shape packages/contracts pins', function () {
        // Without this the schema is decorative: the mobile type is generated
        // from it, but nothing would catch the API drifting out of that shape —
        // and a renamed field surfaces as a blank dashboard on a phone rather
        // than a red test here.
        [$place, $operator] = dashboardVenue();
        $user = User::factory()->create(['is_influencer' => true]);
        $influencer = dashboardInfluencer($user);

        dashboardCode($place, $influencer, RedemptionStatus::Redeemed, $operator, 'AAAAAAAAAA');
        dashboardCode($place, $influencer, RedemptionStatus::Expired, $operator, 'BBBBBBBBBB');

        $body = $this->actingAs($user)
            ->getJson('/api/v1/me/influencer/dashboard')
            ->assertOk()
            ->json('data');

        assertMatchesContract($body, 'influencer-dashboard');
    });
});
