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
