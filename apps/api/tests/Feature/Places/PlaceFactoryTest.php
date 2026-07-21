<?php

use App\Enums\PlaceStatus;
use App\Services\Geo\GeocodeResult;
use App\Services\Places\PlaceFactory;
use App\Services\Places\ProfileLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PlaceFactory (T-095): builds a pending Place from a geocode or an IG-profile
 * location, clamping every untrusted extraction field to its column limit.
 */
function factory(): PlaceFactory
{
    return app(PlaceFactory::class);
}

function geo(array $overrides = []): GeocodeResult
{
    return new GeocodeResult(
        $overrides['googlePlaceId'] ?? 'ChIJfactory',
        $overrides['canonicalName'] ?? 'Canonical Name',
        '10 Test St',
        $overrides['components'] ?? [
            ['long_name' => 'Test St', 'short_name' => 'Test St', 'types' => ['route']],
            ['long_name' => 'London', 'short_name' => 'London', 'types' => ['locality']],
            ['long_name' => 'United Kingdom', 'short_name' => 'GB', 'types' => ['country']],
        ],
        $overrides['lat'] ?? 51.5,
        $overrides['lng'] ?? -0.13,
        ['restaurant'],
        0.9,
        $overrides['rating'] ?? null,
        $overrides['ratingCount'] ?? null,
        $overrides['reviews'] ?? [],
    );
}

it('creates a pending place from a geocode with the country + coordinates set', function () {
    $place = factory()->create(geo(['rating' => 4.5, 'ratingCount' => 20, 'reviews' => [['text' => 'nice']]]), [
        'name' => 'Extracted',
        'cuisines' => ['ramen'],
        'price_range' => 2,
    ]);

    expect($place->exists)->toBeTrue()
        ->and($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->name)->toBe('Canonical Name')          // canonical wins over extracted
        ->and($place->city)->toBe('London')
        ->and($place->country_code)->toBe('GB')
        ->and($place->cuisine_primary)->toBe('ramen')
        ->and($place->price_range)->toBe(2)
        ->and($place->google_place_id)->toBe('ChIJfactory')
        ->and($place->google_rating_count)->toBe(20)
        ->and($place->coordinates())->toMatchArray(['lat' => 51.5, 'lng' => -0.13]);
});

it('clamps untrusted extraction fields to their column limits', function () {
    $place = factory()->create(geo(), [
        'name' => 'X',
        'cuisines' => [str_repeat('c', 200)],   // cuisine_primary is varchar(64)
        'phone' => str_repeat('9', 100),         // phone is varchar(32)
        'price_range' => 99,                     // outside 1–4 → null
    ]);

    expect(mb_strlen($place->cuisine_primary))->toBe(64)
        ->and(mb_strlen($place->phone))->toBe(32)
        ->and($place->price_range)->toBeNull();
});

it('falls back to the XX country sentinel when neither geocode nor address has one', function () {
    $place = factory()->create(geo(['components' => []]), ['name' => 'NoCountry', 'address' => []]);

    expect($place->country_code)->toBe('XX');
});

it('creates a profile pin with no google_place_id, preferring the profile address', function () {
    $location = new ProfileLocation(
        name: 'La Burger', street: 'Rua A', city: 'Porto', region: 'Norte', postalCode: '4000',
        lat: 41.15, lng: -8.61,
    );

    $place = factory()->createFromProfile('La Burger', ['address' => ['country' => 'PT']], $location);

    expect($place->google_place_id)->toBeNull()
        ->and($place->name)->toBe('La Burger')
        ->and($place->city)->toBe('Porto')
        ->and($place->country_code)->toBe('PT')
        ->and($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->coordinates())->toMatchArray(['lat' => 41.15, 'lng' => -8.61]);
});
