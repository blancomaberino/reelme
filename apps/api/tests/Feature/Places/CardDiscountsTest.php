<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Places\PlaceAggregations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// The payment-cards facet is cached; flush between cases so one test's result
// never leaks into another (RefreshDatabase resets the DB, not the cache).
beforeEach(fn () => Cache::flush());

/*
| T-079 — caption-derived card/bank/wallet discounts: read-time aggregation on
| the place, the PlaceResource payload, the map/index `?card=` filter, and the
| /places/payment-cards facet.
*/

/**
 * A visible place with one source carrying the given raw discount snapshots.
 *
 * @param  list<array<string, mixed>>  $discounts
 */
function placeWithDiscounts(array $discounts, float $lat = 40.0, float $lng = -3.0, string $name = 'Bar Test'): Place
{
    $place = Place::factory()->active()->atPoint($lat, $lng)->create(['name' => $name]);
    $post = SourcePost::factory()->create(['influencer_id' => Influencer::factory()->create()->id]);
    $share = Share::factory()->create([
        'source_post_id' => $post->id,
        'user_id' => User::factory()->create()->id,
    ]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => ['name' => $name, 'discounts' => $discounts],
        'is_primary' => true,
    ]);

    return $place->load('sources');
}

it('aggregates + dedupes discounts across sources, labelling by issuer/scheme/handle', function () {
    $place = placeWithDiscounts([
        ['scheme' => 'Visa', 'issuer' => 'Santander', 'handle' => null, 'terms' => '20% off', 'percent' => 20],
        ['scheme' => null, 'issuer' => null, 'handle' => 'prex.uy', 'terms' => '2x1', 'percent' => null],
        ['scheme' => 'Mastercard', 'issuer' => null, 'handle' => null, 'terms' => '10% off', 'percent' => 10],
    ]);

    // A second source repeats the Santander offer (dedupe) and adds one.
    $post = SourcePost::factory()->create(['influencer_id' => Influencer::factory()->create()->id]);
    $share = Share::factory()->create(['source_post_id' => $post->id, 'user_id' => User::factory()->create()->id]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => ['discounts' => [
            ['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20], // dup
            ['issuer' => 'Itaú', 'terms' => '3 cuotas', 'percent' => null],
        ]],
    ]);

    $discounts = PlaceAggregations::discounts($place->load('sources'));
    $cards = array_column($discounts, 'card');

    expect($cards)->toContain('Santander')   // issuer preferred over scheme
        ->toContain('@prex.uy')              // handle fallback when no name
        ->toContain('Mastercard')            // scheme when no issuer
        ->toContain('Itaú')
        // Santander appears once despite two identical entries.
        ->and(array_filter($cards, fn ($c) => $c === 'Santander'))->toHaveCount(1);
});

it('agrees between the shown label and the filter for a handle stored with a leading @', function () {
    // A snapshot handle that (against spec) already carries a leading @ must not
    // double-prepend: aggregation shows "@prex.uy" and ?card=@prex.uy matches it.
    $place = placeWithDiscounts([['handle' => '@prex.uy', 'terms' => '2x1', 'percent' => null]], 40.0, -3.0, 'Prex Bar');

    expect(PlaceAggregations::discounts($place))->toBe([
        ['card' => '@prex.uy', 'terms' => '2x1', 'percent' => null],
    ]);

    $rows = $this->getJson('/api/v1/places?'.http_build_query(['card' => '@prex.uy']))->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['name'])->toBe('Prex Bar');
});

it('tolerates a non-array discounts value in a snapshot (filter + facet do not error)', function () {
    // A malformed/legacy snapshot with a scalar `discounts` must be skipped, not
    // crash jsonb_array_elements.
    $place = Place::factory()->active()->atPoint(40.0, -3.0)->create(['name' => 'Legacy']);
    $post = SourcePost::factory()->create(['influencer_id' => Influencer::factory()->create()->id]);
    $share = Share::factory()->create(['source_post_id' => $post->id, 'user_id' => User::factory()->create()->id]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => ['discounts' => 'nonsense'],
    ]);

    expect(PlaceAggregations::discounts($place->load('sources')))->toBe([]);
    $this->getJson('/api/v1/places?card=visa')->assertOk();
    $this->getJson('/api/v1/places/payment-cards')->assertOk()->assertJsonPath('data', []);
});

it('keeps filter + facet in lockstep with the shown chips for empty terms', function () {
    // A discount with a real card but whitespace-only terms is dropped from the
    // shown chips — the filter and facet must not surface a card that has no chip.
    placeWithDiscounts([['issuer' => 'Ghost', 'terms' => '   ', 'percent' => null]], 40.0, -3.0, 'Ghost Bar');

    expect(PlaceAggregations::discounts(Place::where('name', 'Ghost Bar')->first()->load('sources')))->toBe([]);
    expect($this->getJson('/api/v1/places?card=ghost')->assertOk()->json('data'))->toBe([]);
    expect($this->getJson('/api/v1/places/payment-cards')->assertOk()->json('data'))->toBe([]);
});

it('drops discounts with no card label or no terms', function () {
    $place = placeWithDiscounts([
        ['scheme' => null, 'issuer' => null, 'handle' => null, 'terms' => 'no card here', 'percent' => null],
        ['issuer' => 'BROU', 'terms' => '', 'percent' => null],
        ['issuer' => 'BROU', 'terms' => '15% off', 'percent' => 15],
    ]);

    expect(PlaceAggregations::discounts($place))->toBe([
        ['card' => 'BROU', 'terms' => '15% off', 'percent' => 15],
    ]);
});

it('exposes discounts on the place detail payload', function () {
    $place = placeWithDiscounts([['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20]]);

    $data = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    expect($data['discounts'])->toBe([
        ['card' => 'Santander', 'terms' => '20% off', 'percent' => 20],
    ]);
});

it('filters the browse index by card (issuer, scheme, or @handle)', function () {
    placeWithDiscounts([['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20]], 40.0, -3.0, 'Santander Bar');
    placeWithDiscounts([['scheme' => 'Visa', 'terms' => '10% off', 'percent' => 10]], 40.1, -3.1, 'Visa Bar');
    placeWithDiscounts([['handle' => 'prex.uy', 'terms' => '2x1', 'percent' => null]], 40.2, -3.2, 'Prex Bar');

    $byIssuer = $this->getJson('/api/v1/places?card=santander')->assertOk()->json('data');
    expect($byIssuer)->toHaveCount(1)->and($byIssuer[0]['name'])->toBe('Santander Bar');

    $byScheme = $this->getJson('/api/v1/places?card=visa')->assertOk()->json('data');
    expect($byScheme)->toHaveCount(1)->and($byScheme[0]['name'])->toBe('Visa Bar');

    $byHandle = $this->getJson('/api/v1/places?'.http_build_query(['card' => '@prex.uy']))->assertOk()->json('data');
    expect($byHandle)->toHaveCount(1)->and($byHandle[0]['name'])->toBe('Prex Bar');

    // A card nobody offers returns nothing (not everything).
    expect($this->getJson('/api/v1/places?card=amex')->assertOk()->json('data'))->toBe([]);
});

it('filters the map viewport by card', function () {
    // Two places inside a small central-London viewport.
    placeWithDiscounts([['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20]], 51.5117, -0.1300, 'Santander Bar');
    placeWithDiscounts([['scheme' => 'Visa', 'terms' => '10% off', 'percent' => 10]], 51.5000, -0.1000, 'Visa Bar');

    $res = $this->getJson('/api/v1/map/places?bbox=-0.20,51.45,-0.05,51.55&zoom=16&card=santander')->assertOk();

    expect($res->json('meta.total_in_bbox'))->toBe(1);
    expect(collect($res->json('data.pins'))->pluck('name'))
        ->toContain('Santander Bar')->not->toContain('Visa Bar');
});

it('lists distinct discount cards, most-offered first, via the facet endpoint', function () {
    placeWithDiscounts([['issuer' => 'Santander', 'terms' => '20% off', 'percent' => 20]], 40.0, -3.0, 'A');
    placeWithDiscounts([['issuer' => 'Santander', 'terms' => '5% off', 'percent' => 5]], 40.1, -3.1, 'B');
    placeWithDiscounts([['scheme' => 'Visa', 'terms' => '10% off', 'percent' => 10]], 40.2, -3.2, 'C');

    $cards = $this->getJson('/api/v1/places/payment-cards')->assertOk()->json('data');

    expect($cards[0])->toBe(['card' => 'Santander', 'count' => 2])
        ->and($cards)->toContain(['card' => 'Visa', 'count' => 1]);
});
