<?php

use App\Models\Place;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Contract tests (T-128): every review payload must validate against
 * packages/contracts/schemas/review.json — the file the mobile `Review` type is
 * generated from, and which the place-detail `reviews` embed `$ref`s.
 *
 * FOUR shapes reach a client, and validating only one is how CR-4 stayed
 * invisible for months. `review.json`'s own description names three endpoints
 * besides the embed; a schema that CLAIMS to describe a payload nothing checks
 * is a promise, not a contract.
 *
 * The write paths are not merely a fourth copy of the same query, either:
 * `ReviewController::reviewResponse()` does
 * `$review->setRelation('user', $request->user())`, so `author` there is built
 * from the REQUEST user rather than the loaded DB relation. That is the one
 * seam where the write shape can drift from the read shape, and it is the
 * reason these live in their own file rather than as an extra assertion in
 * ReviewApiTest.
 */
function contractReviewPlace(): Place
{
    return Place::factory()->active()->atPoint(51.5, -0.13)->create();
}

it('validates the POST response against review.json', function () {
    $place = contractReviewPlace();
    Sanctum::actingAs(User::factory()->create(['is_public' => true]));

    $row = $this->postJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 5,
        'body' => 'Worth the walk.',
    ])->assertCreated()->json('data');

    assertMatchesContract($row, 'review');
    // The author came from the request user, not the relation — assert it is
    // actually populated, or the schema check above passes on `author: null`
    // and proves nothing about the branch this endpoint takes.
    expect($row['author'])->not->toBeNull();
    expect($row['is_own'])->toBeTrue();
});

it('validates the PUT response against review.json, for a public and a private author', function () {
    $place = contractReviewPlace();

    Sanctum::actingAs(User::factory()->create(['is_public' => true]));
    $public = $this->putJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 4,
        'body' => 'Good.',
    ])->assertOk()->json('data');
    assertMatchesContract($public, 'review');
    expect($public['author'])->not->toBeNull();

    // A private reviewer must be withheld on the WRITE path too. `author` is
    // set from `$request->user()` here, bypassing the relation the read path
    // loads — so the `is_public` policy has to hold in ReviewResource itself.
    // If it ever moved to the query, this response would leak the identity and
    // only this test would notice.
    Sanctum::actingAs(User::factory()->create(['is_public' => false]));
    $private = $this->putJson("/api/v1/places/{$place->id}/reviews", [
        'rating' => 2,
        'body' => null,
    ])->assertOk()->json('data');
    assertMatchesContract($private, 'review');
    expect($private['author'])->toBeNull();
    expect($private['body'])->toBeNull();
});

it('validates every row of the reviews list against review.json', function () {
    $place = contractReviewPlace();
    Review::factory()->create([
        'place_id' => $place->id,
        'user_id' => User::factory()->create(['is_public' => true])->id,
    ]);
    Review::factory()->create([
        'place_id' => $place->id,
        'user_id' => User::factory()->create(['is_public' => false])->id,
    ]);

    $rows = $this->getJson("/api/v1/places/{$place->id}/reviews")->assertOk()->json('data');
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        assertMatchesContract($row, 'review');
    }

    // Both `anyOf` branches of `author`, so neither is left unexercised.
    $authors = array_map(fn (array $r) => $r['author'], $rows);
    expect($authors)->toContain(null);
    expect(array_filter($authors))->not->toBeEmpty();
});

it('serves is_own as a boolean for a guest reading the list', function () {
    $place = contractReviewPlace();
    Review::factory()->create([
        'place_id' => $place->id,
        'user_id' => User::factory()->create(['is_public' => true])->id,
    ]);

    // Unauthenticated: `$request->user('sanctum')?->id === $this->user_id`
    // compares null to an int. The schema requires a boolean, and a guest is
    // the caller most likely to be served a null here.
    $rows = $this->getJson("/api/v1/places/{$place->id}/reviews")->assertOk()->json('data');

    expect($rows[0]['is_own'])->toBeFalse();
    assertMatchesContract($rows[0], 'review');
});
