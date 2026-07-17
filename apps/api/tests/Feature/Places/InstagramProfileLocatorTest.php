<?php

use App\Services\Media\Instagram\InstagramWebClient;
use App\Services\Places\InstagramProfileLocator;
use Illuminate\Support\Facades\Http;

/**
 * InstagramProfileLocator mines a venue's IG profile for a location (T-075) when
 * the geocoder missed. Driven through a real InstagramWebClient + Http::fake so
 * the whole client→locator path is exercised, asserting the signal priority
 * (business_address → bio 📍 → full_name), the never-throws contract, and the
 * per-handle cache.
 */
function locatorCookie(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'lck_');
    file_put_contents($path, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\n");

    return $path;
}

function makeLocator(): InstagramProfileLocator
{
    return new InstagramProfileLocator(new InstagramWebClient(cookiesPath: locatorCookie(), timeout: 5));
}

/** Fake the profile endpoint with a given `data.user` node. */
function fakeProfile(array $user): void
{
    Http::fake(['www.instagram.com/api/v1/users/web_profile_info*' => Http::response(['data' => ['user' => $user]], 200)]);
}

it('resolves a full business account to direct coordinates + structured address', function () {
    fakeProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => json_encode([
            'street_address' => 'Ruta 84 km 3',
            'city_name' => 'Barros Blancos',
            'region_name' => 'Canelones',
            'zip_code' => '91000',
            'latitude' => -34.62,
            'longitude' => -56.02,
        ]),
    ]);

    $loc = makeLocator()->locate('lagranburgerok');

    expect($loc)->not->toBeNull()
        ->and($loc->name)->toBe('La Gran Burger')
        ->and($loc->street)->toBe('Ruta 84 km 3')
        ->and($loc->city)->toBe('Barros Blancos')
        ->and($loc->region)->toBe('Canelones')
        ->and($loc->postalCode)->toBe('91000')
        ->and($loc->lat)->toBe(-34.62)
        ->and($loc->lng)->toBe(-56.02)
        ->and($loc->hasCoordinates())->toBeTrue();
});

it('falls back to a 📍 bio locality when the business address is empty (the @lagranburgerok case)', function () {
    fakeProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => '', // professional account that never set an address
        'biography' => '🥩 Burger de asado 📍Barros Blancos 🛵 Delivery',
    ]);

    $loc = makeLocator()->locate('lagranburgerok');

    expect($loc)->not->toBeNull()
        ->and($loc->name)->toBe('La Gran Burger')
        ->and($loc->city)->toBe('Barros Blancos') // stops at the next emoji
        ->and($loc->hasCoordinates())->toBeFalse();
});

it('upgrades a bare handle to the real full_name even with no address at all', function () {
    fakeProfile(['full_name' => 'Café Central', 'biography' => 'best coffee in town']);

    $loc = makeLocator()->locate('cafecentral');

    expect($loc)->not->toBeNull()
        ->and($loc->name)->toBe('Café Central')
        ->and($loc->hasLocality())->toBeFalse()
        ->and($loc->hasCoordinates())->toBeFalse();
});

it('treats a null-island (0,0) business coordinate as no coordinate', function () {
    fakeProfile([
        'full_name' => 'X',
        'business_address_json' => json_encode(['city_name' => 'Lisboa', 'latitude' => 0, 'longitude' => 0]),
    ]);

    $loc = makeLocator()->locate('x');

    expect($loc->city)->toBe('Lisboa')
        ->and($loc->hasCoordinates())->toBeFalse();
});

it('returns null when the profile carries no usable signal', function () {
    fakeProfile(['full_name' => '', 'biography' => 'dm for collabs', 'business_address_json' => '']);

    expect(makeLocator()->locate('emptyacct'))->toBeNull();
});

it('caches per handle within the instance — a repeated handle fetches once', function () {
    fakeProfile(['full_name' => 'Once Only', 'business_address_json' => json_encode(['latitude' => 1, 'longitude' => 2])]);
    $locator = makeLocator();

    $a = $locator->locate('venue');
    $b = $locator->locate('@VENUE'); // normalized to the same key

    expect($a->name)->toBe('Once Only')
        ->and($b)->toEqual($a);
    Http::assertSentCount(1);
});

it('returns null gracefully when the profile fetch fails (no cookie / 4xx), keeping geocode_failed', function () {
    Http::fake(['*' => Http::response('', 403)]);
    expect(makeLocator()->locate('venue'))->toBeNull();

    Http::fake();
    $noCookie = new InstagramProfileLocator(new InstagramWebClient(cookiesPath: null));
    expect($noCookie->locate('venue'))->toBeNull();
    Http::assertNothingSent();
});
