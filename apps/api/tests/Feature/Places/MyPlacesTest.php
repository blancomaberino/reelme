<?php

use App\Models\FeedDismissal;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\Tag;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /me/places (T-071, ADR-071) — the personal "my places" list: the list
 * view of my map. Places I shared (published, not soft-hidden) ∪ places I saved.
 */
function myPlace(string $name, array $attrs = []): Place
{
    return Place::factory()->active()->atPoint(51.51, -0.13)->create(['name' => $name, ...$attrs]);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/me/places')->assertStatus(401);
});

it('lists my shared ∪ saved places, never others’', function () {
    $me = User::factory()->create();
    $shared = myPlace('Shared');
    $saved = myPlace('Saved');
    $stranger = myPlace('Stranger');

    publishedShare($shared, sharer: $me);
    publishedShare($stranger); // someone else's

    $list = PlaceList::factory()->for($me)->create();
    $list->items()->create(['place_id' => $saved->id, 'position' => 1]);

    Sanctum::actingAs($me);
    $names = collect($this->getJson('/api/v1/me/places')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Shared')->toContain('Saved')->not->toContain('Stranger');
});

it('drops a place shared only via a soft-hidden share', function () {
    $me = User::factory()->create();
    $hidden = myPlace('Hidden');
    $share = publishedShare($hidden, sharer: $me);
    FeedDismissal::create(['user_id' => $me->id, 'share_id' => $share->id]);

    Sanctum::actingAs($me);
    $names = collect($this->getJson('/api/v1/me/places')->assertOk()->json('data'))->pluck('name');

    expect($names)->not->toContain('Hidden');
});

it('filters by country, type, and tag facets', function () {
    $me = User::factory()->create();
    $pt = myPlace('Lisbon spot', ['country_code' => 'PT', 'cuisine_primary' => 'seafood']);
    $es = myPlace('Madrid spot', ['country_code' => 'ES', 'cuisine_primary' => 'tapas']);
    publishedShare($pt, sharer: $me);
    publishedShare($es, sharer: $me);

    $tag = Tag::factory()->create(['slug' => 'brunch', 'name' => 'Brunch']);
    $pt->tags()->attach($tag->id, ['source' => 'extraction']);

    Sanctum::actingAs($me);

    // country
    $byCountry = collect($this->getJson('/api/v1/me/places?country=pt')->assertOk()->json('data'))->pluck('name');
    expect($byCountry)->toContain('Lisbon spot')->not->toContain('Madrid spot');

    // type (cuisine)
    $byType = collect($this->getJson('/api/v1/me/places?type=tapas')->assertOk()->json('data'))->pluck('name');
    expect($byType)->toContain('Madrid spot')->not->toContain('Lisbon spot');

    // tag
    $byTag = collect($this->getJson('/api/v1/me/places?tags[]=brunch')->assertOk()->json('data'))->pluck('name');
    expect($byTag)->toContain('Lisbon spot')->not->toContain('Madrid spot');
});

it('carries per-row `mine` provenance (share_id when shared, saved flag)', function () {
    $me = User::factory()->create();
    $sharedOnly = myPlace('SharedOnly');
    $savedOnly = myPlace('SavedOnly');

    $share = publishedShare($sharedOnly, sharer: $me);

    $list = PlaceList::factory()->for($me)->create();
    $list->items()->create(['place_id' => $savedOnly->id, 'position' => 1]);

    Sanctum::actingAs($me);
    $rows = collect($this->getJson('/api/v1/me/places')->assertOk()->json('data'))->keyBy('name');

    expect($rows['SharedOnly']['mine'])->toBe(['share_id' => (string) $share->id, 'saved' => false]);
    expect($rows['SavedOnly']['mine'])->toBe(['share_id' => null, 'saved' => true]);
});

it('reports a place shared-and-saved with both a share_id and saved=true', function () {
    $me = User::factory()->create();
    $both = myPlace('Both');
    $share = publishedShare($both, sharer: $me);
    $list = PlaceList::factory()->for($me)->create();
    $list->items()->create(['place_id' => $both->id, 'position' => 1]);

    Sanctum::actingAs($me);
    $row = collect($this->getJson('/api/v1/me/places')->assertOk()->json('data'))->firstWhere('name', 'Both');
    expect($row['mine'])->toBe(['share_id' => (string) $share->id, 'saved' => true]);
});

it('omits share_id in `mine` once my share is soft-hidden but I still saved it', function () {
    $me = User::factory()->create();
    $p = myPlace('Kept');
    $share = publishedShare($p, sharer: $me);
    FeedDismissal::create(['user_id' => $me->id, 'share_id' => $share->id]);
    $list = PlaceList::factory()->for($me)->create();
    $list->items()->create(['place_id' => $p->id, 'position' => 1]);

    Sanctum::actingAs($me);
    $row = collect($this->getJson('/api/v1/me/places')->assertOk()->json('data'))->firstWhere('name', 'Kept');
    // Present via the save; the dismissed share is no longer offered for removal.
    expect($row['mine'])->toBe(['share_id' => null, 'saved' => true]);
});

it('rows validate against the place-summary contract and carry a thumbnail_url key', function () {
    $me = User::factory()->create();
    $p = myPlace('Card');
    publishedShare($p, sharer: $me);

    Sanctum::actingAs($me);
    $rows = $this->getJson('/api/v1/me/places')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(ApiSchema::errors(ApiSchema::validate($row, 'place-summary')))->toBe([]);
        expect($row)->toHaveKey('thumbnail_url');
    }
});

it('paginates by keyset cursor without gaps or repeats', function () {
    $me = User::factory()->create();
    foreach (range(1, 3) as $i) {
        publishedShare(myPlace("Place {$i}"), sharer: $me);
    }

    Sanctum::actingAs($me);
    $first = $this->getJson('/api/v1/me/places?limit=2')->assertOk();
    $firstNames = collect($first->json('data'))->pluck('name');
    $cursor = $first->json('meta.pagination.next_cursor');
    expect($firstNames)->toHaveCount(2)->and($cursor)->not->toBeNull();

    $second = collect($this->getJson('/api/v1/me/places?limit=2&cursor='.urlencode($cursor))->assertOk()->json('data'))->pluck('name');
    expect($second)->toHaveCount(1)
        ->and($firstNames->intersect($second))->toBeEmpty();
});

it('sorts by popular (shares_count) and paginates that sort', function () {
    $me = User::factory()->create();
    publishedShare(myPlace('Cold', ['shares_count' => 1]), sharer: $me);
    publishedShare(myPlace('Hot', ['shares_count' => 9]), sharer: $me);
    publishedShare(myPlace('Warm', ['shares_count' => 5]), sharer: $me);

    Sanctum::actingAs($me);
    $res = $this->getJson('/api/v1/me/places?sort=popular&limit=2')->assertOk();
    expect(collect($res->json('data'))->pluck('name')->all())->toBe(['Hot', 'Warm']);

    $next = $this->getJson('/api/v1/me/places?sort=popular&limit=2&cursor='.urlencode($res->json('meta.pagination.next_cursor')))->assertOk();
    expect(collect($next->json('data'))->pluck('name')->all())->toBe(['Cold']);
});

it('rejects a malformed cursor with the validation envelope, not a 500', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);

    // A well-formed base64url cursor for this sort, but an unparseable timestamp.
    $bad = rtrim(strtr(base64_encode((string) json_encode(['s' => 'my-places-recent', 'k' => ['2026-13-40 00:00:00.000000', 1]])), '+/', '-_'), '=');

    $this->getJson('/api/v1/me/places?cursor='.urlencode($bad))
        ->assertStatus(422)
        ->assertJsonPath('error.details.cursor.0', 'The cursor is malformed.');
});
