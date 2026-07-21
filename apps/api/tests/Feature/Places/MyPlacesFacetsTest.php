<?php

use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /me/places/facets (T-088) — distinct country + type over the FULL personal
 * collection, so the filter chips aren't silently capped at the first loaded page.
 */
function facetPlace(string $country, ?string $cuisine = null): Place
{
    return Place::factory()->active()->atPoint(51.51, -0.13)
        ->create(['country_code' => $country, 'cuisine_primary' => $cuisine]);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/me/places/facets')->assertStatus(401);
});

it('returns distinct countries and types across the FULL collection, not just page 1', function () {
    $me = User::factory()->create();

    // The PT place is created FIRST → lowest id → with the `recent` sort
    // (created_at desc, id desc) it lands LAST, beyond the 20-row first page.
    // This is exactly the country the old page-1-only facet derivation missed.
    publishedShare(facetPlace('PT', 'sushi'), sharer: $me);

    // …then 25 GB places, all more recent, filling and overflowing page 1.
    for ($i = 0; $i < 25; $i++) {
        publishedShare(facetPlace('GB', 'ramen'), sharer: $me);
    }

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/facets')->assertOk()->json('data');

    // Both the overflow country/type AND the page-1 ones are present, deduped+sorted.
    expect($data['countries'])->toBe(['GB', 'PT'])
        ->and($data['types'])->toBe(['ramen', 'sushi']);
});

it('scopes facets to my collection — never a stranger’s countries, and counts saved places', function () {
    $me = User::factory()->create();

    // Mine via a published share.
    publishedShare(facetPlace('ES', 'tapas'), sharer: $me);
    // Mine via a saved list (not shared) — must still contribute a facet.
    $saved = facetPlace('IT', 'pizza');
    PlaceList::factory()->for($me)->create()->items()->create(['place_id' => $saved->id, 'position' => 1]);
    // A stranger's place — must NOT leak into my facets.
    publishedShare(facetPlace('JP', 'ramen'));

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/facets')->assertOk()->json('data');

    expect($data['countries'])->toContain('ES')->toContain('IT')->not->toContain('JP')
        ->and($data['types'])->toContain('tapas')->toContain('pizza')->not->toContain('ramen');
});

it('excludes a place I have soft-hidden and drops null facet values', function () {
    $me = User::factory()->create();

    publishedShare(facetPlace('FR', null), sharer: $me);   // null cuisine → no type facet
    $hidden = facetPlace('DE', 'currywurst');
    publishedShare($hidden, sharer: $me);
    HiddenPlace::create(['user_id' => $me->id, 'place_id' => $hidden->id]);

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/facets')->assertOk()->json('data');

    expect($data['countries'])->toBe(['FR'])       // DE hidden, no nulls
        ->and($data['types'])->toBe([]);            // FR's cuisine was null → empty
});

it('returns empty facet arrays when I have no places', function () {
    Sanctum::actingAs(User::factory()->create());
    $data = $this->getJson('/api/v1/me/places/facets')->assertOk()->json('data');
    expect($data)->toBe(['countries' => [], 'types' => []]);
});
