<?php

use App\Services\Geo\Exceptions\GeocodeFailed;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;
use App\Services\Geo\GooglePlacesGeocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google_places.key', 'test-key');
    Cache::flush();
    Http::preventStrayRequests();
});

function placesFixture(string $file): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/google-places/{$file}")), true, flags: JSON_THROW_ON_ERROR);
}

function geocoder(): GooglePlacesGeocoder
{
    return new GooglePlacesGeocoder;
}

it('maps find-place + details into a GeocodeResult', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(placesFixture('findplace.json')),
        '*/details/json*' => Http::response(placesFixture('details.json')),
    ]);

    $result = geocoder()->findPlace('Lanzhou Noodle House', new GeoHints(city: 'Lisbon', country: 'PT'));

    expect($result)->toBeInstanceOf(GeocodeResult::class)
        ->and($result->googlePlaceId)->toBe('ChIJN1t_tDeuEmsRUsoyG83frY4')
        ->and($result->canonicalName)->toBe('Lanzhou Noodle House')
        ->and($result->formattedAddress)->toBe('Rua da Prata 12, 1100-052 Lisboa, Portugal')
        ->and($result->lat)->toBe(38.710979)
        ->and($result->lng)->toBe(-9.137619)
        ->and($result->types)->toContain('restaurant')
        ->and($result->addressComponents)->toHaveCount(5)
        ->and($result->score)->toBeGreaterThan(0.5)
        ->and($result->rating)->toBe(4.6)
        ->and($result->ratingCount)->toBe(214)
        ->and($result->reviews)->toHaveCount(2)
        ->and($result->reviews[0])->toMatchArray([
            'author' => 'Maria S.',
            'rating' => 5,
            'text' => 'Best hand-pulled noodles in Lisbon.',
            'relative_time' => '2 weeks ago',
            'time' => 1699000000,
        ]);
});

it('sends the minimal field mask on the details request (billing guard)', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(placesFixture('findplace.json')),
        '*/details/json*' => Http::response(placesFixture('details.json')),
    ]);

    geocoder()->findPlace('Lanzhou Noodle House', new GeoHints(city: 'Lisbon'));

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/details/json')) {
            return false;
        }

        return $request['fields'] === 'place_id,name,formatted_address,address_component,geometry/location,type,rating,user_ratings_total,reviews';
    });
});

it('passes the language hint and location bias to find-place', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(placesFixture('findplace.json')),
        '*/details/json*' => Http::response(placesFixture('details.json')),
    ]);

    geocoder()->findPlace('Café', new GeoHints(city: 'Lisboa', lat: 38.7, lng: -9.1, language: 'pt'));

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/findplacefromtext/json')) {
            return false;
        }

        return $request['language'] === 'pt' && $request['locationbias'] === 'point:38.7,-9.1';
    });
});

it('returns null on ZERO_RESULTS without calling details', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(['candidates' => [], 'status' => 'ZERO_RESULTS']),
    ]);

    expect(geocoder()->findPlace('Nowhere Imaginary', new GeoHints))->toBeNull();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/details/json'));
});

it('throws GeocodeFailed on a provider error status (retryable, not cached)', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(['status' => 'OVER_QUERY_LIMIT', 'error_message' => 'quota']),
    ]);

    $call = fn () => geocoder()->findPlace('Somewhere', new GeoHints);

    expect($call)->toThrow(GeocodeFailed::class);
    // The error must not have been cached — a retry hits Google again.
    expect(Cache::has('geocode:'.sha1('somewhere||')))->toBeFalse();
});

it('throws GeocodeFailed on an HTTP 5xx', function () {
    Http::fake(['*/findplacefromtext/json*' => Http::response('boom', 500)]);

    geocoder()->findPlace('Somewhere', new GeoHints);
})->throws(GeocodeFailed::class);

it('never leaks the API key into the exception on a connection error', function () {
    config()->set('services.google_places.key', 'super-secret-key');
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: timeout for https://maps.googleapis.com/maps/api/place/findplacefromtext/json?input=x&key=super-secret-key'
    ));

    try {
        geocoder()->findPlace('Somewhere', new GeoHints);
        expect()->fail('expected GeocodeFailed');
    } catch (GeocodeFailed $e) {
        // Neither the message nor any chained previous may contain the key.
        $chain = $e->getMessage().' '.($e->getPrevious()?->getMessage() ?? '');
        expect($chain)->not->toContain('super-secret-key');
    }
});

it('caches a hit — a repeat identical lookup makes zero HTTP calls', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(placesFixture('findplace.json')),
        '*/details/json*' => Http::response(placesFixture('details.json')),
    ]);

    $first = geocoder()->findPlace('Lanzhou Noodle House', new GeoHints(city: 'Lisbon'));
    Http::assertSentCount(2);

    $second = geocoder()->findPlace('Lanzhou Noodle House', new GeoHints(city: 'Lisbon'));

    // Still only the original 2 requests — the second lookup was served from cache.
    Http::assertSentCount(2);
    expect($second->googlePlaceId)->toBe($first->googlePlaceId);
});

it('caches a miss too — a repeat unmatchable lookup makes zero HTTP calls', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(['candidates' => [], 'status' => 'ZERO_RESULTS']),
    ]);

    expect(geocoder()->findPlace('Ghost Place', new GeoHints))->toBeNull();
    Http::assertSentCount(1);

    expect(geocoder()->findPlace('Ghost Place', new GeoHints))->toBeNull();
    Http::assertSentCount(1);
});

it('normalizes lookalike queries onto the same cache key (accents + case + spacing)', function () {
    Http::fake([
        '*/findplacefromtext/json*' => Http::response(placesFixture('findplace.json')),
        '*/details/json*' => Http::response(placesFixture('details.json')),
    ]);

    geocoder()->findPlace('Café  São', new GeoHints);
    Http::assertSentCount(2);

    // "cafe sao" normalizes to the same key → served from cache, no new HTTP.
    geocoder()->findPlace('cafe sao', new GeoHints);
    Http::assertSentCount(2);
});

it('scores name similarity and boosts on a matching locality', function () {
    $g = geocoder();

    $exact = $g->score('Lanzhou Noodle House', 'Lanzhou Noodle House', 'Rua da Prata, Lisboa', new GeoHints(city: 'Lisboa'));
    $weak = $g->score('Lanzhou Noodle House', 'Totally Different Bar', 'Elsewhere', new GeoHints);

    expect($exact)->toBe(1.0)
        ->and($weak)->toBeLessThan(0.5);
});

it('FakeGeocoder returns seeded results and records calls, null otherwise', function () {
    $fake = new FakeGeocoder;
    $seeded = new GeocodeResult('ChIJfake', 'Seeded Spot', 'Some Address', [], 1.0, 2.0, ['restaurant'], 0.9);
    $fake->seed('Seeded Spot', $seeded);

    expect($fake->findPlace('Seeded Spot', new GeoHints))->toBe($seeded)
        ->and($fake->findPlace('Unknown', new GeoHints))->toBeNull()
        ->and($fake->calls)->toHaveCount(2)
        ->and($fake->calls[0]['name'])->toBe('Seeded Spot');
});
