<?php

use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * HTTP rate limits (T-051, 03 §1).
 *
 * Laravel's stock 429 is a plain-text body, so the interesting question is not
 * "does it throttle" but "does the DEVICE get something it can act on" — the
 * `rate_limited` envelope and a `Retry-After` it can show a human. A test that
 * only asserts the status code passes while the app renders garbage.
 */
beforeEach(function () {
    RateLimiter::clear('api:1');
    config(['quotas.rate.default' => 3, 'quotas.rate.public' => 2, 'quotas.rate.polling' => 2]);
});

it('answers a throttled request in the standard error envelope', function () {
    $user = User::factory()->create();
    $share = Share::factory()->for($user)->create();

    $last = null;
    foreach (range(1, 4) as $ignored) {
        $last = $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}");
    }

    $last->assertStatus(429)
        // 03 §1: every error on this API is `{error: {code, message, details,
        // request_id}}`. A device that special-cases 429 into a raw string is
        // a device that shows the user a stack of plain text.
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

    // And the headers a client needs to back off intelligently rather than
    // hammering until it works.
    expect($last->headers->get('Retry-After'))->not->toBeNull()
        ->and((int) $last->headers->get('X-RateLimit-Limit'))->toBe(2)
        ->and($last->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

it('gives share-status polling its own, larger budget', function () {
    // AnalysisStatus polls at 24/min. On the shared default limiter one screen
    // would eat most of a minute's allowance and the app would throttle a user
    // for behaving exactly as designed.
    config(['quotas.rate.polling' => 5, 'quotas.rate.default' => 1]);

    $user = User::factory()->create();
    $share = Share::factory()->for($user)->create();

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertOk();
    }

    // The default limiter (1/min here) would have cut this off after one.
    $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertStatus(429);
});

it('keys authenticated limits on the user, never the IP', function () {
    config(['quotas.rate.polling' => 1]);

    $first = User::factory()->create();
    $second = User::factory()->create();
    $shareA = Share::factory()->for($first)->create();
    $shareB = Share::factory()->for($second)->create();

    $this->actingAs($first)->getJson("/api/v1/shares/{$shareA->id}")->assertOk();
    $this->actingAs($first)->getJson("/api/v1/shares/{$shareA->id}")->assertStatus(429);

    // Same IP (every test request is 127.0.0.1), different account. Mobile
    // carriers NAT thousands of subscribers behind one address, so an IP-keyed
    // authenticated limit throttles a city because one person was busy.
    $this->actingAs($second)->getJson("/api/v1/shares/{$shareB->id}")->assertOk();
});

it('reads its ceilings from config so they can be raised without a deploy', function () {
    // FR-58. A limiter nobody can raise during an incident is a limiter
    // somebody removes during an incident.
    config(['quotas.rate.polling' => 1]);
    $user = User::factory()->create();
    $share = Share::factory()->for($user)->create();

    $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertOk();
    $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertStatus(429);

    // NOT cleared between the two halves — deliberately. A NAMED limiter hashes
    // its cache key (`md5($limiterName.$limit->key)`), so `RateLimiter::clear
    // ('polling:1')` clears NOTHING and would have made this look like a reset
    // that never happened. The two hits already recorded simply fit under the
    // new ceiling, which is the actual claim: the limit is re-read per request.
    config(['quotas.rate.polling' => 10]);

    $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertOk();
});

it('refuses a share past the daily allowance, and says when it comes back', function () {
    config(['quotas.daily.shares' => 1]);
    $user = User::factory()->create();
    Share::factory()->for($user)->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/OVER/'])
        ->assertStatus(429);

    // The daily cap is enforced in the CONTROLLER, not as a `Limit::perDay` —
    // that is a rolling 86,400s window anchored to the user's first request,
    // which would disagree with the midnight-UTC reset `/me` reports. Two
    // mechanisms answering "when do I get more" differently is the bug.
    expect($response->json('error.message'))
        ->toContain(Carbon::now('UTC')->startOfDay()->addDay()->toIso8601String());

    // And nothing was written. A refused share that still costs a row would
    // make the next day's count wrong too.
    expect(Share::where('user_id', $user->id)->count())->toBe(1);
});

it('lets the share through while the allowance lasts', function () {
    config(['quotas.daily.shares' => 2]);
    $user = User::factory()->create();
    Share::factory()->for($user)->create();

    // The other side of the boundary — a cap that refuses at N-1 is not a cap,
    // it is an off-by-one, and the test above cannot tell the difference.
    $this->actingAs($user)
        ->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/UNDER/'])
        ->assertAccepted();
});

it('keys redemption verify on the staff account, not the till it is standing on', function () {
    config(['quotas.rate.verify' => 1]);

    $placeA = Place::factory()->active()->create();
    $placeB = Place::factory()->active()->create();
    $operatorA = operatorOfPlace($placeA);
    $operatorB = operatorOfPlace($placeB);

    Redemption::factory()->withCode('AAAABBBBCC')->create([
        'offer_id' => Offer::factory()->active()->create(['place_id' => $placeA->id])->id,
    ]);
    Redemption::factory()->withCode('CCCCDDDDEE')->create([
        'offer_id' => Offer::factory()->active()->create(['place_id' => $placeB->id])->id,
    ]);

    $this->actingAs($operatorA)
        ->postJson('/api/v1/redemptions/verify', ['code' => 'AAAABBBBCC', 'place_id' => (string) $placeA->id])
        ->assertOk();
    $this->actingAs($operatorA)
        ->postJson('/api/v1/redemptions/verify', ['code' => 'AAAABBBBCC', 'place_id' => (string) $placeA->id])
        ->assertStatus(429);

    // Same IP, different shop. This was a raw `throttle:30,1` — i.e. per IP —
    // so one busy counter behind a NAT would have throttled the shop next door.
    $this->actingAs($operatorB)
        ->postJson('/api/v1/redemptions/verify', ['code' => 'CCCCDDDDEE', 'place_id' => (string) $placeB->id])
        ->assertOk();
});
