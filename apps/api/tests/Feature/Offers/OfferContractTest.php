<?php

use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;

/**
 * Contract tests (T-102): every offer payload must validate against
 * packages/contracts/schemas/offer.json — the file the mobile `Offer` type is
 * generated from.
 *
 * The three shapes differ, which is the point of testing all of them: the flat
 * reads carry the `place` block, the place-detail embed omits it, and the
 * operator's own view carries states (`draft`, `paused`) a diner never sees.
 */
it('validates a browse row against offer.json', function () {
    $place = Place::factory()->active()->create();
    Offer::factory()->active()->create(['place_id' => $place->id]);
    Offer::factory()->fixedAmount()->active()->create(['place_id' => $place->id]);
    Offer::factory()->freeItem()->active()->create(['place_id' => $place->id]);

    $rows = $this->getJson('/api/v1/offers')->assertOk()->json('data');
    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        assertMatchesContract($row, 'offer');
        expect($row)->toHaveKey('place');
    }
});

it('validates the detail payload', function () {
    $offer = Offer::factory()->active()->create([
        'place_id' => Place::factory()->active(),
        'quota_total' => 50,
        'quota_per_day' => 5,
        'redemptions_count' => 7,
    ]);

    $row = $this->getJson("/api/v1/offers/{$offer->id}")->assertOk()->json('data');

    assertMatchesContract($row, 'offer');
    expect($row['remaining_quota'])->toBe(43);
});

it('validates an offer with every nullable field empty', function () {
    $offer = Offer::factory()->active()->create([
        'place_id' => Place::factory()->active(),
        'description' => null,
        'terms' => null,
        'ends_at' => null,
        'quota_total' => null,
        'quota_per_day' => null,
    ]);

    $row = $this->getJson("/api/v1/offers/{$offer->id}")->assertOk()->json('data');

    assertMatchesContract($row, 'offer');
    expect($row['remaining_quota'])->toBeNull()
        ->and($row['ends_at'])->toBeNull();
});

it('validates the embedded offers on place detail, which carry no place block', function () {
    $place = Place::factory()->active()->create();
    Offer::factory()->active()->count(2)->create(['place_id' => $place->id]);

    $offers = $this->getJson("/api/v1/places/{$place->slug}?include=offers")->assertOk()->json('data.offers');
    expect($offers)->toHaveCount(2);

    foreach ($offers as $offer) {
        assertMatchesContract($offer, 'offer');
        expect($offer)->not->toHaveKey('place');
    }
});

it('validates the operator management view, drafts included', function () {
    $place = Place::factory()->active()->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);
    Offer::factory()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);
    Offer::factory()->paused()->create(['place_id' => $place->id, 'created_by_user_id' => $operator->id]);

    $rows = $this->actingAs($operator)->getJson('/api/v1/offers?mine=1')->assertOk()->json('data');
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        assertMatchesContract($row, 'offer');
    }
});

it('validates the create and update responses', function () {
    $place = Place::factory()->active()->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);

    $created = $this->actingAs($operator)->postJson('/api/v1/offers', [
        'place_id' => $place->id,
        'title' => 'Free dessert',
        'discount_type' => 'free_item',
        'discount_value' => 1,
        'starts_at' => now()->toIso8601String(),
        'status' => 'active',
    ])->assertCreated()->json('data');

    assertMatchesContract($created, 'offer');

    $updated = $this->actingAs($operator)
        ->patchJson("/api/v1/offers/{$created['id']}", ['status' => 'paused'])
        ->assertOk()->json('data');

    assertMatchesContract($updated, 'offer');

    $archived = $this->actingAs($operator)
        ->deleteJson("/api/v1/offers/{$created['id']}")
        ->assertOk()->json('data');

    assertMatchesContract($archived, 'offer');
});

/**
 * `/me/venues` is a route T-042 adds beyond 03 §2.12 (see ADR-042), so its
 * payload needs the same pinning as the offer shapes — it feeds the mobile
 * venue picker through the shared PlaceSummary type.
 */
it('validates the operated-venue list against place-summary.json', function () {
    $place = Place::factory()->active()->atPoint(38.7223, -9.1393)->create();
    $operator = User::factory()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $place->id, 'user_id' => $operator->id]);
    // A venue someone else operates must not appear.
    $others = Place::factory()->active()->create();
    PlaceClaim::factory()->verified()->create(['place_id' => $others->id, 'user_id' => User::factory()]);

    $rows = $this->actingAs($operator)->getJson('/api/v1/me/venues')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe((string) $place->id);
    assertMatchesContract($rows[0], 'place-summary');
});

it('returns nothing for a user who operates no venue', function () {
    Place::factory()->active()->create();

    expect($this->actingAs(User::factory()->create())->getJson('/api/v1/me/venues')->assertOk()->json('data'))
        ->toBe([]);
});
