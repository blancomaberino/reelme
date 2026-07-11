<?php

use App\Services\Geo\Exceptions\GeocodeFailed;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeoHints;
use App\Services\Geo\GooglePlacesGeocoder;
use App\Services\Geo\NominatimGeocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function nominatimRow(): array
{
    return [[
        'osm_type' => 'node',
        'osm_id' => 456,
        'lat' => '38.7169',
        'lon' => '-9.1399',
        'name' => 'Time Out Market',
        'display_name' => 'Time Out Market, Avenida 24 de Julho, Lisbon, Portugal',
        'category' => 'amenity',
        'type' => 'restaurant',
        'importance' => 0.4,
        'address' => [
            'road' => 'Avenida 24 de Julho',
            'city' => 'Lisbon',
            'state' => 'Lisboa',
            'postcode' => '1200-109',
            'country' => 'Portugal',
            'country_code' => 'pt',
        ],
    ]];
}

it('maps a Nominatim hit onto a GeocodeResult', function () {
    Http::fake(['*/search*' => Http::response(nominatimRow())]);

    $result = (new NominatimGeocoder)->findPlace('Time Out Market', new GeoHints(city: 'Lisbon', country: 'Portugal'));

    expect($result)->not->toBeNull()
        ->and($result->canonicalName)->toBe('Time Out Market')
        ->and(round($result->lat, 4))->toBe(38.7169)
        ->and(round($result->lng, 4))->toBe(-9.1399)
        ->and($result->googlePlaceId)->toBe('osm:node:456')
        ->and($result->score)->toBeGreaterThanOrEqual(0.6);

    // Country component resolves to the ISO-2 code the resolver needs.
    $country = collect($result->addressComponents)->firstWhere('types', ['country', 'political']);
    expect($country['short_name'])->toBe('PT');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'format=jsonv2')
        && str_contains(urldecode($r->url()), 'Time Out Market, Lisbon, Portugal'));
});

it('returns null on no match and caches the miss', function () {
    Http::fake(['*/search*' => Http::response([])]);

    $geocoder = new NominatimGeocoder;
    expect($geocoder->findPlace('Nowhere Cafe', new GeoHints))->toBeNull();

    // Second call is served from the cached miss — no second HTTP request.
    expect($geocoder->findPlace('Nowhere Cafe', new GeoHints))->toBeNull();
    Http::assertSentCount(1);
});

it('raises GeocodeFailed on a provider error without leaking the query', function () {
    Http::fake(['*/search*' => Http::response('', 503)]);

    expect(fn () => (new NominatimGeocoder)->findPlace('Secret Spot', new GeoHints))
        ->toThrow(GeocodeFailed::class);
});

it('binds Nominatim as the keyless default when no Google key is configured', function () {
    config()->set('services.google_places.key', null);
    config()->set('geo.driver', 'auto');
    app()->forgetInstance(Geocoder::class);

    expect(app(Geocoder::class))->toBeInstanceOf(NominatimGeocoder::class);

    config()->set('services.google_places.key', 'test-key');
    app()->forgetInstance(Geocoder::class);
    expect(app(Geocoder::class))->toBeInstanceOf(GooglePlacesGeocoder::class);
});
