<?php

use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /users/{username}/places and /lists (T-071) — visiting a user surfaces
 * THEIR published places (list view of their map) and THEIR public Lists, never
 * mixed into anyone else's. Same private-profile 404 gate as the rest of §2.9.
 */
it('lists a user’s published places, not others’', function () {
    $owner = User::factory()->create(['is_public' => true, 'username' => 'chef']);
    $mine = Place::factory()->active()->atPoint(51.51, -0.13)->create(['name' => 'Theirs']);
    $other = Place::factory()->active()->atPoint(51.50, -0.10)->create(['name' => 'Not theirs']);

    publishedShare($mine, sharer: $owner);
    publishedShare($other); // a different user's share

    $names = collect($this->getJson('/api/v1/users/chef/places')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Theirs')->not->toContain('Not theirs');
});

it('404s a private profile’s places for strangers but serves the owner', function () {
    $owner = User::factory()->create(['is_public' => false, 'username' => 'hermit']);
    $p = Place::factory()->active()->atPoint(51.51, -0.13)->create(['name' => 'Secret']);
    publishedShare($p, sharer: $owner);

    $this->getJson('/api/v1/users/hermit/places')->assertStatus(404);

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/users/hermit/places')->assertOk()
        ->assertJsonPath('data.0.name', 'Secret');
});

it('does not leak private-account existence via an invalid facet (404, not 422)', function () {
    User::factory()->create(['is_public' => false, 'username' => 'hermit']);

    // An invalid query param on a private-but-EXISTING profile must 404 (the
    // privacy gate runs before validation) — never 422, which would be an
    // existence oracle vs the 404 an unknown username returns.
    $this->getJson('/api/v1/users/hermit/places?country=TOOLONG')->assertStatus(404);
    $this->getJson('/api/v1/users/hermit/places?sort=nope')->assertStatus(404);
    $this->getJson('/api/v1/users/ghost/places?country=TOOLONG')->assertStatus(404);
});

it('filters a user’s places by the country/type/tag facets', function () {
    $owner = User::factory()->create(['is_public' => true, 'username' => 'facets']);
    $pt = Place::factory()->active()->atPoint(51.51, -0.13)->create(['name' => 'Porto', 'country_code' => 'PT', 'cuisine_primary' => 'seafood']);
    $es = Place::factory()->active()->atPoint(51.50, -0.10)->create(['name' => 'Sevilla', 'country_code' => 'ES', 'cuisine_primary' => 'tapas']);
    publishedShare($pt, sharer: $owner);
    publishedShare($es, sharer: $owner);

    $byCountry = collect($this->getJson('/api/v1/users/facets/places?country=pt')->assertOk()->json('data'))->pluck('name');
    expect($byCountry)->toContain('Porto')->not->toContain('Sevilla');

    $byType = collect($this->getJson('/api/v1/users/facets/places?type=tapas')->assertOk()->json('data'))->pluck('name');
    expect($byType)->toContain('Sevilla')->not->toContain('Porto');
});

it('lists only a user’s PUBLIC lists', function () {
    $owner = User::factory()->create(['is_public' => true, 'username' => 'curator']);
    PlaceList::factory()->for($owner)->create(['name' => 'Public picks', 'is_public' => true]);
    PlaceList::factory()->for($owner)->create(['name' => 'Secret picks', 'is_public' => false]);

    $names = collect($this->getJson('/api/v1/users/curator/lists')->assertOk()->json('data'))->pluck('name');
    expect($names)->toContain('Public picks')->not->toContain('Secret picks');
});

it('exposes public-list item counts and owner attribution', function () {
    $owner = User::factory()->create(['is_public' => true, 'username' => 'lister']);
    $list = PlaceList::factory()->for($owner)->create(['name' => 'Faves', 'is_public' => true]);
    $place = Place::factory()->active()->atPoint(51.51, -0.13)->create();
    $list->items()->create(['place_id' => $place->id, 'position' => 1]);

    $this->getJson('/api/v1/users/lister/lists')->assertOk()
        ->assertJsonPath('data.0.name', 'Faves')
        ->assertJsonPath('data.0.items_count', 1)
        ->assertJsonPath('data.0.is_public', true)
        ->assertJsonPath('data.0.owner.username', 'lister');
});

it('404s a private profile’s lists for strangers', function () {
    User::factory()->create(['is_public' => false, 'username' => 'hidden']);

    $this->getJson('/api/v1/users/hidden/lists')->assertStatus(404);
});
