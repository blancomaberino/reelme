<?php

use App\Models\Place;
use App\Models\PlaceEditSuggestion;
use App\Models\User;

/**
 * Contract tests (T-102): every suggestion payload must validate against
 * packages/contracts/schemas/place-edit-suggestion.json — the file the mobile
 * `PlaceEditSuggestion` type is generated from.
 *
 * Both shapes are exercised because they differ in a way `tsc` cannot see: the
 * submit response has no `place` block (you are on the place), the operator's
 * cross-venue list does, and the mobile screen renders the venue name from it.
 */
it('validates the submit response against place-edit-suggestion.json', function () {
    $place = Place::factory()->create(['phone' => '+598 2 111 1111']);

    $row = $this->actingAs(User::factory()->create())
        ->postJson("/api/v1/places/{$place->id}/suggestions", ['phone' => '+598 2 900 0000'])
        ->assertCreated()
        ->json('data');

    assertMatchesContract($row, 'place-edit-suggestion');
    expect($row)->not->toHaveKey('place');
});

it('validates an operator\'s pending row, with its place block', function () {
    $place = Place::factory()->create();
    $owner = operatorOfPlace($place);
    PlaceEditSuggestion::factory()->create(['place_id' => $place->id]);

    $row = $this->actingAs($owner)
        ->getJson('/api/v1/me/venues/suggestions')
        ->assertOk()
        ->json('data.0');

    assertMatchesContract($row, 'place-edit-suggestion');
    expect($row['place']['slug'])->toBe($place->slug);
});

/**
 * The value types are the part of this schema most likely to drift: opening
 * hours are an array, price range is an integer, everything else is a string,
 * and `from` is null for a field that was empty. One payload carrying all four
 * proves the union in the schema covers what the API actually emits.
 */
it('validates a payload carrying every value shape a change can take', function () {
    $place = Place::factory()->create([
        'phone' => null,
        'price_range' => 2,
        'opening_hours_json' => null,
        'name' => 'Cantina Vieja',
    ]);

    $row = $this->actingAs(User::factory()->create())
        ->postJson("/api/v1/places/{$place->id}/suggestions", [
            'name' => 'Cantina Nueva',
            'phone' => '+598 2 900 0000',
            'price_range' => 3,
            'opening_hours_json' => ['Lu-Vi 12:00–15:00', 'Sa 20:00–23:30'],
        ])
        ->assertCreated()
        ->json('data');

    assertMatchesContract($row, 'place-edit-suggestion');

    $byField = collect($row['changes'])->keyBy('field');
    expect($byField['name']['from'])->toBe('Cantina Vieja')
        ->and($byField['phone']['from'])->toBeNull()
        ->and($byField['price_range']['to'])->toBe(3)
        ->and($byField['opening_hours_json']['to'])->toBe(['Lu-Vi 12:00–15:00', 'Sa 20:00–23:30'])
        // The list order is the allow-list's, not insertion or jsonb order —
        // both surfaces render these top to bottom and must agree.
        ->and(collect($row['changes'])->pluck('field')->all())
        ->toBe(['name', 'price_range', 'phone', 'opening_hours_json']);
});
