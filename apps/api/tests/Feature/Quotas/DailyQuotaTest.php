<?php

use App\Models\Share;
use App\Models\User;
use App\Services\AI\SpendTracker;
use App\Services\Quotas\QuotaSnapshot;
use Illuminate\Support\Carbon;

/**
 * Daily quotas and what the app can see of them (T-051, NFR-12).
 *
 * The point of surfacing these on `GET /me` is that the app can say "daily
 * limit reached — resets at X" *before* the tap. A quota the client cannot see
 * is one it can only discover by hitting it, which turns a designed limit into
 * what looks like a bug.
 */
it('reports share usage, AI spend and the reset time on /me', function () {
    config(['quotas.daily.shares' => 10, 'ai.daily_user_budget' => 0.50]);
    $user = User::factory()->create();
    Share::factory()->count(3)->for($user)->create();
    app(SpendTracker::class)->record($user->id, 0.20);

    $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($response->json('meta.quotas.shares'))->toBe(['used' => 3, 'limit' => 10, 'remaining' => 7])
        ->and($response->json('meta.quotas.ai.spent_usd'))->toBe(0.2)
        ->and($response->json('meta.quotas.ai.remaining_usd'))->toBe(0.3)
        // Midnight UTC, everywhere. A local-midnight reset would make "when
        // does this come back" depend on where the user is standing.
        ->and($response->json('meta.quotas.resets_at'))
        ->toBe(Carbon::now('UTC')->startOfDay()->addDay()->toIso8601String());
});

it('counts only shares from the current UTC day', function () {
    config(['quotas.daily.shares' => 10]);
    $user = User::factory()->create();

    Share::factory()->for($user)->create(['created_at' => Carbon::now('UTC')->startOfDay()->subMinute()]);
    Share::factory()->for($user)->create(['created_at' => Carbon::now('UTC')->startOfDay()->addMinute()]);

    // Yesterday's share must not eat today's allowance — the boundary is the
    // whole promise of a "daily" limit.
    expect(app(QuotaSnapshot::class)->for($user)['shares']['used'])->toBe(1);
});

it('counts only this user, never everybody', function () {
    $user = User::factory()->create();
    Share::factory()->count(2)->create(); // somebody else's

    expect(app(QuotaSnapshot::class)->for($user)['shares']['used'])->toBe(0);
});

it('counts shares from the table, not from a counter a flush can lose', function () {
    config(['quotas.daily.shares' => 5]);
    $user = User::factory()->create();
    Share::factory()->count(5)->for($user)->create();

    // What the app shows a user about their own limit has to be the number we
    // would actually enforce on, and that number is rows. A cache counter is a
    // fast pre-check; a Redis flush must not silently hand somebody a second
    // day's allowance.
    expect(app(QuotaSnapshot::class)->sharesExhausted($user))->toBeTrue();
});

it('never reports a negative remaining budget', function () {
    config(['quotas.daily.shares' => 2, 'ai.daily_user_budget' => 0.10]);
    $user = User::factory()->create();
    Share::factory()->count(5)->for($user)->create();
    app(SpendTracker::class)->record($user->id, 0.90);

    // Over-quota is a real state — the pipeline parks a share rather than
    // erroring — and "-3 shares left" is not something to render at anybody.
    $snapshot = app(QuotaSnapshot::class)->for($user);

    expect($snapshot['shares']['remaining'])->toBe(0)
        ->and($snapshot['ai']['remaining_usd'])->toBe(0.0);
});

it('takes its limits from config', function () {
    config(['quotas.daily.shares' => 42]);
    $user = User::factory()->create();

    // FR-58: tunable without a deploy.
    expect(app(QuotaSnapshot::class)->for($user)['shares']['limit'])->toBe(42);
});
