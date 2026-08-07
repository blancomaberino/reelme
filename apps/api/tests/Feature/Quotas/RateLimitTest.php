<?php

use App\Models\Share;
use App\Models\User;
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

    RateLimiter::clear('polling:'.$user->id);
    config(['quotas.rate.polling' => 10]);

    $this->actingAs($user)->getJson("/api/v1/shares/{$share->id}")->assertOk();
});
