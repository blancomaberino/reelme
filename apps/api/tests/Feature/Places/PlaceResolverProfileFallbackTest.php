<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\ResolvePlace;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\GeoHints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// geoResult(), bindGeocoder() live in tests/Helpers/PipelineHelpers.php.

/** Wire a readable IG cookie so the profile client is ready, and forget cached binds. */
beforeEach(function () {
    $cookie = (string) tempnam(sys_get_temp_dir(), 'pfck_');
    file_put_contents($cookie, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\n");
    config()->set('ingestion.instagram_api.cookies_path', $cookie);
    config()->set('ingestion.instagram_api.enabled', true);
    config()->set('places.ig_profile.enabled', true);
});

/** An analyzing share whose winning run names one venue by @handle. */
function handleShare(string $name, ?string $handle): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing]);
    $run = AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'test-model',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => 0.9,
        'result_json' => [
            'places' => [[
                'name' => $name,
                'handle' => $handle,
                'confidence' => 0.9,
                'address' => ['street' => null, 'city' => null, 'region' => null, 'postal_code' => null, 'country' => null],
                'geo' => null,
                'cuisines' => ['burgers'],
                'price_range' => 1,
                'phone' => null,
                'website' => null,
            ]],
            'post' => ['language' => 'es'],
            'confidence' => ['overall' => 0.9],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $share->analysis_run_id = $run->id;
    $share->save();

    return $share;
}

function fakeIgProfile(array $user): void
{
    Http::fake(['www.instagram.com/api/v1/users/web_profile_info*' => Http::response(['data' => ['user' => $user]], 200)]);
}

it('re-geocodes from the profile full_name + bio locality and resolves with a google_place_id', function () {
    // Geocoder misses the bare handle-name, but hits the enriched full_name query.
    $geo = (new FakeGeocoder)->seed('La Gran Burger', geoResult('ChIJprofile', -34.62, -56.02, name: 'La Gran Burger'));
    bindGeocoder($geo);
    fakeIgProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => '',
        'biography' => '🥩 Burger de asado 📍Barros Blancos 🛵 Delivery',
    ]);
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    $place = Place::sole();
    expect($place->google_place_id)->toBe('ChIJprofile')
        ->and($place->name)->toBe('La Gran Burger') // canonical from the enriched geocode
        ->and($place->status)->toBe(PlaceStatus::Pending)
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing) // resolved → chain continues
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeTrue();

    // Two geocode attempts: the bare name (miss) then the profile-enriched query.
    expect($geo->calls)->toHaveCount(2)
        ->and($geo->calls[1]['name'])->toBe('La Gran Burger')
        ->and($geo->calls[1]['hints'])->toBeInstanceOf(GeoHints::class)
        ->and($geo->calls[1]['hints']->city)->toBe('Barros Blancos'); // bio locality enriched the query
});

it('falls back to the profile business-address coordinates (pending, no google_place_id) when geocoding still misses', function () {
    bindGeocoder(new FakeGeocoder); // seeds nothing — every geocode misses
    fakeIgProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => json_encode([
            'street_address' => 'Ruta 84', 'city_name' => 'Barros Blancos', 'latitude' => -34.62, 'longitude' => -56.02,
        ]),
    ]);
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    $place = Place::sole();
    expect($place->google_place_id)->toBeNull()
        ->and($place->name)->toBe('La Gran Burger') // full_name upgrades the bare handle name
        ->and($place->city)->toBe('Barros Blancos')
        ->and($place->coordinates())->toBe(['lat' => -34.62, 'lng' => -56.02])
        ->and($place->status)->toBe(PlaceStatus::Pending)
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('does not fetch the profile at all when the first geocode already succeeds', function () {
    bindGeocoder((new FakeGeocoder)->seed('bar sin nombre', geoResult('ChIJhit', 40.4, -3.7, name: 'Bar Sin Nombre')));
    Http::fake();
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    expect(Place::sole()->google_place_id)->toBe('ChIJhit')
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);
    Http::assertNothingSent(); // geocode hit → the @handle profile is never looked up
});

it('the profile-coordinates fallback attaches to an existing nearby pin instead of duplicating', function () {
    bindGeocoder(new FakeGeocoder); // every geocode misses → drops to the coords fallback
    fakeIgProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => json_encode(['city_name' => 'Barros Blancos', 'latitude' => -34.62, 'longitude' => -56.02]),
    ]);
    // An existing pending pin at the same point + name, no google_place_id.
    $existing = Place::factory()->atPoint(-34.62, -56.02)->create(['name' => 'La Gran Burger', 'google_place_id' => null]);
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(1) // attached, not duplicated
        ->and(PlaceSource::where('share_id', $share->id)->where('place_id', $existing->id)->exists())->toBeTrue()
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('the profile-coordinates fallback routes to review when several nearby pins match', function () {
    bindGeocoder(new FakeGeocoder);
    fakeIgProfile([
        'full_name' => 'La Gran Burger',
        'business_address_json' => json_encode(['latitude' => -34.62, 'longitude' => -56.02]),
    ]);
    Place::factory()->atPoint(-34.62, -56.02)->create(['name' => 'La Gran Burger']);
    Place::factory()->atPoint(-34.62, -56.02)->create(['name' => 'La Gran Burger']);
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('ambiguous_place')
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeFalse();
});

it('parks the share (no profile fetch) when the venue has no handle', function () {
    bindGeocoder(new FakeGeocoder); // misses
    Http::fake(); // any IG call would be a failure of the no-handle guard
    $share = handleShare('mystery diner', null);

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->review_reason)->toBe('geocode_failed');
    Http::assertNothingSent(); // no @handle → no profile lookup at all
});

it('keeps geocode_failed when the profile yields no usable location', function () {
    bindGeocoder(new FakeGeocoder); // misses
    fakeIgProfile(['full_name' => '', 'biography' => 'dm for collabs', 'business_address_json' => '']);
    $share = handleShare('bar sin nombre', 'emptyacct');

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->review_reason)->toBe('geocode_failed');
});

it('does not attempt the profile fallback when the feature is disabled', function () {
    config()->set('places.ig_profile.enabled', false);
    bindGeocoder(new FakeGeocoder); // misses
    Http::fake();
    $share = handleShare('bar sin nombre', 'lagranburgerok');

    (new ResolvePlace($share->id))->handle();

    expect($share->fresh()->status)->toBe(ShareStatus::Review);
    Http::assertNothingSent();
});
