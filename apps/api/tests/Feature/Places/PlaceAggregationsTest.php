<?php

use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Places\PlaceAggregations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * PlaceAggregations (T-096): cross-source tag + discount aggregation, split out
 * of the Place god model — plus the SQL↔PHP twin-drift guard that pins
 * Place::DISCOUNT_CARD_SQL to PlaceAggregations::discountCard().
 */
function placeWithSnapshots(array $snapshots): Place
{
    $place = Place::factory()->create();
    foreach ($snapshots as $snapshot) {
        PlaceSource::factory()->create([
            'place_id' => $place->id,
            'extraction_snapshot_json' => $snapshot,
        ]);
    }

    return $place->load(['sources.dishes']);
}

it('unions and dedupes tags across sources, filling a missing dish price from a later source', function () {
    $place = placeWithSnapshots([
        ['cuisines' => ['ramen', 'japanese'], 'vibe_tags' => ['cosy'], 'dishes' => [['name' => 'Tonkotsu', 'shown_in_video' => true]]],
        ['cuisines' => ['ramen'], 'dietary_tags' => ['vegan'], 'dishes' => [['name' => 'Tonkotsu', 'price' => '12€']]],
    ]);

    $tags = PlaceAggregations::tags($place);

    expect($tags['cuisines'])->toBe(['ramen', 'japanese'])   // deduped, first-seen order
        ->and($tags['vibe_tags'])->toBe(['cosy'])
        ->and($tags['dietary_tags'])->toBe(['vegan'])
        ->and($tags['dishes'])->toHaveCount(1);              // one dish, price backfilled
    expect($tags['dishes'][0])->toMatchArray(['name' => 'Tonkotsu', 'shown_in_video' => true, 'price' => '12€']);
});

it('unions and dedupes discounts by (card, terms) across sources', function () {
    $place = placeWithSnapshots([
        ['discounts' => [['issuer' => 'Amex', 'terms' => '10% off', 'percent' => 10]]],
        ['discounts' => [['issuer' => 'Amex', 'terms' => '10% off', 'percent' => 10]]], // dup → collapses
        ['discounts' => [['scheme' => 'Visa', 'terms' => 'free coffee']]],
    ]);

    $discounts = PlaceAggregations::discounts($place);

    expect($discounts)->toHaveCount(2)
        ->and(collect($discounts)->pluck('card')->all())->toBe(['Amex', 'Visa']);
});

it('pins Place::DISCOUNT_CARD_SQL to PlaceAggregations::discountCard() over a fixture set (twin-drift guard)', function () {
    // Every branch of the resolved issuer → scheme → @handle rule, plus the
    // trim / leading-@ collapse edge cases that must agree on both sides.
    $fixtures = [
        ['issuer' => 'Amex', 'scheme' => 'visa', 'handle' => '@bank'],  // issuer wins
        ['issuer' => '   ', 'scheme' => 'Visa', 'handle' => 'x'],       // blank issuer → scheme
        ['scheme' => '  ', 'handle' => '@santander'],                    // blank scheme → handle
        ['handle' => 'santander'],                                       // bare handle → @santander
        ['handle' => '  @revolut  '],                                    // trim + keep single @
        ['handle' => '@@@'],                                             // all-@ collapses to empty
        ['issuer' => 'Chase Bank'],
        ['scheme' => 'mastercard'],
        [],                                                             // nothing → empty
    ];

    foreach ($fixtures as $discount) {
        $sql = DB::selectOne(
            'SELECT COALESCE('.Place::DISCOUNT_CARD_SQL.", '') AS card FROM (SELECT ?::jsonb AS d) t",
            [json_encode($discount)],
        )->card;

        $php = PlaceAggregations::discountCard($discount);

        expect($sql)->toBe($php, 'SQL/PHP card label diverged for '.json_encode($discount));
    }
});

/**
 * T-157 acceptance: `PlaceAggregations::tags()` now reads the `dishes` table
 * instead of re-parsing `extraction_snapshot_json`, and the switch must be
 * invisible. `legacyDishes()` below is the code it replaced, kept HERE (not in
 * the app) so this is a genuine before/after comparison rather than the new
 * implementation agreeing with itself.
 */
function legacyDishes(Place $place): array
{
    $dishes = [];

    foreach ($place->sources as $source) {
        $snapshot = $source->extraction_snapshot_json;
        if (! is_array($snapshot['dishes'] ?? null)) {
            continue;
        }
        foreach ($snapshot['dishes'] as $dish) {
            if (! is_array($dish)) {
                continue;
            }
            $name = trim((string) ($dish['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $priceRaw = $dish['price'] ?? null;
            $price = is_string($priceRaw) && trim($priceRaw) !== '' ? trim($priceRaw) : null;
            if (isset($dishes[$name])) {
                if ($dishes[$name]['price'] === null && $price !== null) {
                    $dishes[$name]['price'] = $price;
                }

                continue;
            }
            $dishes[$name] = [
                'name' => $name,
                'shown_in_video' => (bool) ($dish['shown_in_video'] ?? false),
                'price' => $price,
            ];
        }
    }

    return array_values($dishes);
}

it('reads dishes from the table with output identical to the JSONB parse it replaced', function (array $snapshots) {
    $place = placeWithSnapshots($snapshots);

    expect(PlaceAggregations::tags($place)['dishes'])->toBe(legacyDishes($place));
})->with([
    'no dishes key' => [[['cuisines' => ['italian']]]],
    'empty list' => [[['dishes' => []]]],
    'single source' => [[['dishes' => [['name' => 'Chivito', 'shown_in_video' => true, 'price' => '$450']]]]],
    'price backfilled by a later source' => [[
        ['dishes' => [['name' => 'Tonkotsu', 'shown_in_video' => true]]],
        ['dishes' => [['name' => 'Tonkotsu', 'price' => '12€']]],
    ]],
    'first source wins shown_in_video' => [[
        ['dishes' => [['name' => 'Fainá', 'shown_in_video' => false]]],
        ['dishes' => [['name' => 'Fainá', 'shown_in_video' => true]]],
    ]],
    'case-distinct names stay distinct' => [[['dishes' => [
        ['name' => 'Pasta', 'shown_in_video' => true],
        ['name' => 'pasta', 'shown_in_video' => false],
    ]]]],
    'whitespace-padded names are trimmed' => [[['dishes' => [['name' => '  Ñoquis  ', 'shown_in_video' => true]]]]],
    'blank + non-array entries are dropped' => [[['dishes' => [
        ['name' => '   ', 'shown_in_video' => true],
        'not an object',
        ['shown_in_video' => true],
        ['name' => 'Milanesa', 'shown_in_video' => true],
    ]]]],
    'blank price is null, not an empty string' => [[['dishes' => [['name' => 'Asado', 'shown_in_video' => true, 'price' => '   ']]]]],
    'non-string price is ignored' => [[['dishes' => [['name' => 'Asado', 'shown_in_video' => true, 'price' => 450]]]]],
    'ordered across three sources' => [[
        ['dishes' => [['name' => 'A', 'shown_in_video' => true], ['name' => 'B', 'shown_in_video' => false]]],
        ['dishes' => [['name' => 'B', 'shown_in_video' => true], ['name' => 'C', 'shown_in_video' => false]]],
        ['dishes' => [['name' => 'A', 'shown_in_video' => false, 'price' => '$1']]],
    ]],
]);

it('leaves a moderated source\'s dishes out of the aggregate, exactly as it leaves out its tags', function () {
    // PlaceController::show() drops blocked accounts' sources from the load;
    // dishes are read THROUGH those sources, so a dish cannot walk back in on a
    // contribution the aggregate has already excluded.
    $place = placeWithSnapshots([
        ['dishes' => [['name' => 'Visible', 'shown_in_video' => true]]],
        ['dishes' => [['name' => 'Hidden', 'shown_in_video' => true]]],
    ]);
    $keep = $place->sources->first()->id;

    $narrowed = Place::query()->whereKey($place->id)
        ->with(['sources' => fn ($q) => $q->whereKey($keep)->with('dishes')])
        ->first();

    expect(collect(PlaceAggregations::tags($narrowed)['dishes'])->pluck('name')->all())->toBe(['Visible']);
});
