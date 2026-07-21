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

    return $place->load('sources');
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
