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

/**
 * `?include=0` (found reviewing T-128). `CsvList::parse()` used a bare
 * `array_filter`, which drops "0" for being falsey — so the unknown member
 * never reached `withValidator()` and the request was answered 200 with nothing
 * embedded. The 422 this route promises for a typo was unreachable for exactly
 * one spelling of a typo.
 */
it('answers 422 for an include of "0", which a falsey filter used to swallow', function () {
    $place = Place::factory()->active()->create();

    // The envelope is this API's own (`error.details`), not Laravel's bare
    // `errors` — and naming the member proves the 422 is ABOUT the "0" rather
    // than some other rule tripping on the same request.
    $this->getJson("/api/v1/places/{$place->slug}?include=0")
        ->assertStatus(422)
        ->assertJsonPath('error.details.include.0', 'Unknown include: 0.');

    // And a real include beside it still fails as a set, rather than the "0"
    // vanishing and the rest passing.
    $this->getJson("/api/v1/places/{$place->slug}?include=sources,0")
        ->assertStatus(422)
        ->assertJsonPath('error.details.include.0', 'Unknown include: 0.');

    // The control: the same request without the "0" is a 200, so the 422 above
    // is the new member and not the shape of the URL.
    $this->getJson("/api/v1/places/{$place->slug}?include=sources")->assertOk();
});
