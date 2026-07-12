<?php

use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\Share;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

// publishedShare() lives in tests/Helpers/PipelineHelpers.php (loaded via
// Pest.php) — shared with the Profiles suite, so it must exist in every
// parallel worker.

it('lists published shares reverse-chron with full attribution + place summary', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Feed Cafe']);
    $old = publishedShare($place, publishedAt: now()->subHours(3)->toDateTimeString());
    $new = publishedShare($place, publishedAt: now()->subHour()->toDateTimeString());

    // Noise that must NOT appear: an unpublished share.
    Share::factory()->create(['status' => ShareStatus::Review]);

    $res = $this->getJson('/api/v1/feed')->assertOk();

    $items = $res->json('data');
    expect(collect($items)->pluck('id')->all())->toBe([(string) $new->id, (string) $old->id])
        ->and($res->json('meta.scope'))->toBe('global');

    $item = $items[0];
    expect($item['sharer']['username'])->not->toBeNull()
        ->and($item['influencer']['handle'])->not->toBeNull()
        ->and($item['source_post']['url'])->not->toBeNull()
        ->and($item['source_post']['caption'])->toContain('noodles')
        ->and($item['place']['name'])->toBe('Feed Cafe')
        ->and($item['place']['slug'])->not->toBeNull()
        ->and($item['place']['lat'])->toEqualWithDelta(38.7169, 0.001)
        ->and($item['published_at'])->not->toBeNull();
});

it('walks pages via cursor without duplicates or gaps', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    foreach (range(1, 5) as $i) {
        publishedShare($place, publishedAt: now()->subMinutes($i)->toDateTimeString());
    }

    $page1 = $this->getJson('/api/v1/feed?limit=2')->assertOk();
    $page2 = $this->getJson('/api/v1/feed?limit=2&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();
    $page3 = $this->getJson('/api/v1/feed?limit=2&cursor='.urlencode($page2->json('meta.pagination.next_cursor')))->assertOk();

    $ids = collect([...$page1->json('data'), ...$page2->json('data'), ...$page3->json('data')])->pluck('id');
    expect($ids)->toHaveCount(5)
        ->and($ids->unique())->toHaveCount(5)
        ->and($page3->json('meta.pagination.next_cursor'))->toBeNull();
});

it('stays stable when new shares are published mid-walk (cursor, not offset)', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    foreach (range(1, 3) as $i) {
        publishedShare($place, publishedAt: now()->subMinutes($i + 10)->toDateTimeString());
    }

    $page1 = $this->getJson('/api/v1/feed?limit=2')->assertOk();
    $seen = collect($page1->json('data'))->pluck('id');

    // A NEW share lands at the top of the feed between page fetches.
    publishedShare($place, publishedAt: now()->toDateTimeString());

    $page2 = $this->getJson('/api/v1/feed?limit=2&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();
    $ids = $seen->merge(collect($page2->json('data'))->pluck('id'));

    // No duplicates, no skipped rows from the original set.
    expect($ids->unique())->toHaveCount($ids->count())
        ->and($ids)->toHaveCount(3);
});

it('excludes shares of merged places and withholds private sharers', function () {
    $survivor = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $merged = Place::factory()->atPoint(38.7, -9.1)->create([
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
    ]);
    publishedShare($merged);

    $private = User::factory()->create(['is_public' => false]);
    $visible = publishedShare($survivor, sharer: $private);

    $res = $this->getJson('/api/v1/feed')->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([(string) $visible->id])
        ->and($res->json('data.0.sharer'))->toBeNull();
});

it('gates scope=following behind auth and stubs it empty', function () {
    $this->getJson('/api/v1/feed?scope=following')->assertStatus(401);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/feed?scope=following')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.scope', 'following');
});

it('validates scope/limit/cursor and exposes rate-limit headers', function () {
    $this->getJson('/api/v1/feed?scope=bogus')->assertStatus(422);
    $this->getJson('/api/v1/feed?limit=200')->assertStatus(422);
    $this->getJson('/api/v1/feed?cursor=garbage')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $crafted = rtrim(strtr(base64_encode((string) json_encode(['s' => 'feed', 'k' => ['2026-13-40 99:99:99.000000', 1]])), '+/', '-_'), '=');
    $this->getJson('/api/v1/feed?cursor='.urlencode($crafted))->assertStatus(422);

    $this->getJson('/api/v1/feed')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});

it('422s a cursor whose id key is a non-representable float, on feed and places', function () {
    $craft = fn (string $sort, array $keys) => rtrim(strtr(base64_encode((string) json_encode(['s' => $sort, 'k' => $keys])), '+/', '-_'), '=');

    $this->getJson('/api/v1/feed?cursor='.urlencode($craft('feed', ['2026-07-11 10:00:00.000000', 1e300])))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->getJson('/api/v1/places?cursor='.urlencode($craft('recent', ['2026-07-11 10:00:00.000000', 1e300])))
        ->assertStatus(422);

    $this->getJson('/api/v1/places?sort=popular&cursor='.urlencode($craft('popular', [1, 1e300])))
        ->assertStatus(422);
});
