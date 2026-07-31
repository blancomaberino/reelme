<?php

use App\Http\ApiResponse;
use App\Models\Device;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;

/**
 * Every v1 success response carries `{data, meta}` (03 §1) — T-105.
 *
 * Five endpoints used to omit `meta` outright, because each hand-built its own
 * envelope and nothing checked. Consolidating them behind
 * {@see ApiResponse} fixed that as a side effect; this is what stops
 * it drifting back. The assertion is on the raw decoded body, not
 * `assertJson()`, because assertJson passes on a SUBSET — it cannot see a
 * missing key.
 */
it('includes a meta object on the endpoints that used to omit it', function (string $method, string $uri) {
    $user = User::factory()->create();

    $body = $this->actingAs($user)->json($method, $uri, $method === 'POST'
        ? ['token' => 'ExponentPushToken[test]', 'platform' => 'ios']
        : [])->assertSuccessful()->json();

    expect($body)->toHaveKeys(['data', 'meta']);
})->with([
    'device registration' => ['POST', '/api/v1/devices'],
    'my discovery tags' => ['GET', '/api/v1/me/places/tags'],
    'my place facets' => ['GET', '/api/v1/me/places/facets'],
    'payment-card facets' => ['GET', '/api/v1/places/payment-cards'],
]);

it('includes meta on a public profile lists response', function () {
    $owner = User::factory()->create(['is_public' => true]);
    PlaceList::factory()->for($owner)->create(['is_public' => true]);

    $body = $this->getJson("/api/v1/users/{$owner->username}/lists")->assertOk()->json();

    expect($body)->toHaveKeys(['data', 'meta']);
});

/**
 * The distinction the `(object)` cast exists for: an empty `meta` must encode
 * as `{}`, not `[]`. Decoding erases it, so assert on the raw body.
 */
it('encodes an empty meta as a JSON object, never an array', function () {
    $content = $this->getJson('/api/v1/health')->assertOk()->getContent();

    expect($content)->toContain('"meta":{}')
        ->and($content)->not->toContain('"meta":[]');
});

it('keeps the pagination block on a paginated endpoint', function () {
    Place::factory()->count(2)->create();

    $meta = $this->getJson('/api/v1/places?limit=1')->assertOk()->json('meta');

    expect($meta['pagination'])->toHaveKeys(['next_cursor', 'prev_cursor', 'limit'])
        ->and($meta['pagination']['limit'])->toBe(1)
        ->and($meta['pagination']['next_cursor'])->toBeString()
        ->and($meta['pagination']['prev_cursor'])->toBeNull();
});

it('offers no cursor when the page exactly exhausts the collection', function () {
    // The off-by-one, end to end: 2 rows and limit=2 is a full page with
    // nothing after it, so a next_cursor here would cost the client a round
    // trip that returns nothing.
    Place::factory()->count(2)->create();

    expect($this->getJson('/api/v1/places?limit=2')->assertOk()->json('meta.pagination.next_cursor'))
        ->toBeNull();
});

it('walks a keyset list to the end without repeating or dropping a row', function () {
    $created = Place::factory()->count(5)->create()->pluck('slug')->sort()->values()->all();

    $seen = [];
    $cursor = null;
    for ($i = 0; $i < 10; $i++) {
        $body = $this->getJson('/api/v1/places?limit=2'.($cursor !== null ? '&cursor='.urlencode($cursor) : ''))
            ->assertOk()->json();
        $seen = array_merge($seen, array_column($body['data'], 'slug'));
        $cursor = $body['meta']['pagination']['next_cursor'];
        if ($cursor === null) {
            break;
        }
    }

    expect($cursor)->toBeNull()
        ->and(array_unique($seen))->toHaveCount(5)
        ->and(collect($seen)->sort()->values()->all())->toBe($created);
});

it('registers exactly one device row per token (the POST is idempotent)', function () {
    // Guards the status the migrated DeviceController still has to distinguish.
    $user = User::factory()->create();
    $payload = ['token' => 'ExponentPushToken[dup]', 'platform' => 'ios'];

    $this->actingAs($user)->postJson('/api/v1/devices', $payload)->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/devices', $payload)->assertOk();

    expect(Device::where('expo_push_token', 'ExponentPushToken[dup]')->count())->toBe(1);
});
