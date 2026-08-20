<?php

use App\Models\Offer;
use App\Models\Place;

/**
 * A live offer at a published venue — the only shape a diner can issue against.
 * `$place` defaults to a venue of its own, for the tests that need the offer to
 * be reachable without caring where it is.
 *
 * Lives here rather than in a test file for the reason {@see operatorOfPlace()}
 * gives: a function declared inside a Pest test file is global only once THAT
 * file is loaded. Three suites had each grown their own name for this one
 * fixture, all of them resolvable in a full run and none of them alone.
 *
 * @param  array<string, mixed>  $attributes
 */
function activeOfferAt(?Place $place = null, array $attributes = []): Offer
{
    return Offer::factory()->active()->create($attributes + [
        'place_id' => $place?->id ?? Place::factory()->active(),
    ]);
}
