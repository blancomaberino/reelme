<?php

use App\Models\Place;

/**
 * Array-valued query parameters on the public read routes (found reviewing
 * T-042).
 *
 * `prepareForValidation()` runs BEFORE the rules, so a `string` rule cannot
 * protect a cast inside it. `?near[]=1&near[]=2` made `(string) $array` raise a
 * PHP warning, which Laravel's handler promotes to an ErrorException — a 500 on
 * public, unauthenticated endpoints where a 422 belongs.
 *
 * The offers route is T-042's; `/places` and `/map/places` carry the identical
 * construct it was copied from and were fixed with it.
 */
it('answers 422, not 500, for an array-valued near on the offer browse', function () {
    $this->getJson('/api/v1/offers?near[]=38.7&near[]=-9.1')->assertStatus(422);
});

it('answers 422, not 500, for an array-valued near on the place index', function () {
    Place::factory()->active()->create();

    $this->getJson('/api/v1/places?near[]=38.7&near[]=-9.1')->assertStatus(422);
});

it('answers 422, not 500, for an array-valued bbox on the map', function () {
    $this->getJson('/api/v1/map/places?bbox[]=-9.2&bbox[]=38.7&zoom=14')->assertStatus(422);
});

/** The scalar form still works — the guard narrows the type, it does not break it. */
it('still accepts a well-formed near', function () {
    $this->getJson('/api/v1/offers?near=38.7223,-9.1393&radius_m=2000')->assertOk();
    $this->getJson('/api/v1/places?near=38.7223,-9.1393')->assertOk();
});

/**
 * Same construct, same 500, on the place DETAIL (found reviewing T-128).
 * `PlaceShowRequest::includes()` did `(string) $this->query('include', '')`, and
 * `withValidator()`'s `after` closure runs even when the `string` rule has
 * ALREADY failed — so the cast still ran on an array, warned, and became an
 * ErrorException. The docblock promised a 422 for a bad include the whole time.
 */
it('answers 422, not 500, for an array-valued include on the place detail', function () {
    $place = Place::factory()->active()->create();

    $this->getJson("/api/v1/places/{$place->slug}?include[]=sources&include[]=offers")
        ->assertStatus(422)
        ->assertJsonPath('error.details.include.0', 'The include field must be a string.');
});

/** The scalar forms still work — the guard narrows the type, it does not break it. */
it('still accepts a well-formed include, and still 422s an unknown member', function () {
    $place = Place::factory()->active()->create();

    $this->getJson("/api/v1/places/{$place->slug}?include=sources")->assertOk();
    $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    // The unknown-member branch is what `includes()` feeds, so prove the guard
    // did not silently turn every include into "none requested".
    $this->getJson("/api/v1/places/{$place->slug}?include=sources,nope")
        ->assertStatus(422)
        ->assertJsonPath('error.details.include.0', 'Unknown include: nope.');
});
