<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Geo\Exceptions\GeocodeFailed;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// geoResult(), analyzingShare(), bindGeocoder() live in tests/Helpers/PipelineHelpers.php
// (loaded via Pest.php) so sibling suites can use them under --parallel.

it('attaches to an existing place on an exact google_place_id match', function () {
    $existing = Place::factory()->atPoint(51.5117, -0.1300)->withGooglePlaceId('ChIJexisting')->create(['name' => 'Old Name']);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJexisting', 51.5200, -0.1400)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(1)
        ->and(PlaceSource::where('share_id', $share->id)->where('place_id', $existing->id)->exists())->toBeTrue()
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing); // resolved → chain continues to publish
});

it('fuzzy-matches within 75m + ≥0.85 similarity and backfills the google_place_id', function () {
    $existing = Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou Beef Noodle House', 'google_place_id' => null]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJbackfill', 51.5117, -0.1300)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(1)
        ->and($existing->fresh()->google_place_id)->toBe('ChIJbackfill')
        ->and(PlaceSource::where('place_id', $existing->id)->where('share_id', $share->id)->exists())->toBeTrue();
});

it('routes to review/ambiguous_place when multiple candidates match', function () {
    Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou Beef Noodle House']);
    Place::factory()->atPoint(51.5117, -0.1300)->create(['name' => 'Lanzhou Beef Noodle House']);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJambig', 51.5117, -0.1300)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('ambiguous_place')
        ->and($share->review_meta_json['candidates'])->toHaveCount(2)
        ->and($share->review_meta_json['candidates'][0])->toHaveKeys(['place_id', 'name', 'distance_m', 'similarity', 'lat', 'lng', 'address'])
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeFalse();
});

it('creates a new pending place with a real point when nothing matches', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJnew', 38.7223, -9.1393)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $place = Place::sole();
    expect($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->google_place_id)->toBe('ChIJnew')
        ->and($place->country_code)->toBe('GB')
        ->and($place->coordinates())->toBe(['lat' => 38.7223, 'lng' => -9.1393]) // (lng,lat) order correct
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);

    $source = PlaceSource::sole();
    expect($source->is_primary)->toBeTrue()
        ->and($source->place_id)->toBe($place->id)
        ->and($source->extraction_snapshot_json['name'])->toBe('Lanzhou Beef Noodle House');
});

it('persists the Google rating + review snippets onto a newly created place', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJreviews', 38.7223, -9.1393,
        rating: 4.4,
        ratingCount: 128,
        reviews: [
            ['author' => 'Jane D.', 'rating' => 5, 'text' => 'Incredible.', 'relative_time' => 'a week ago', 'time' => 1700000000],
        ],
    )));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $place = Place::sole();
    expect((float) $place->google_rating)->toBe(4.4)
        ->and($place->google_rating_count)->toBe(128)
        ->and($place->google_reviews_json)->toHaveCount(1)
        ->and($place->google_reviews_json[0]['author'])->toBe('Jane D.');
});

it('backfills the Google rating onto a fuzzy-matched place that lacks it', function () {
    $existing = Place::factory()->atPoint(51.5117, -0.1300)->create([
        'name' => 'Lanzhou Beef Noodle House',
        'google_place_id' => null,
        'google_rating' => null,
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJbackfillrating', 51.5117, -0.1300, rating: 4.1, ratingCount: 12,
    )));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $existing->refresh();
    expect((float) $existing->google_rating)->toBe(4.1)
        ->and($existing->google_rating_count)->toBe(12);
});

it('backfills the Google rating onto a google_place_id-matched place that lacks it', function () {
    $existing = Place::factory()->atPoint(51.5117, -0.1300)->withGooglePlaceId('ChIJgpid')->create([
        'name' => 'Old Name',
        'google_rating' => null,
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJgpid', 51.5200, -0.1400, rating: 4.6, ratingCount: 88,
    )));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $existing->refresh();
    expect((float) $existing->google_rating)->toBe(4.6)
        ->and($existing->google_rating_count)->toBe(88);
});

it('clamps an out-of-range extracted price_range instead of hitting the CHECK', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJclamp', 38.7223, -9.1393)));
    $share = analyzingShare();
    // Overwrite the run's payload with an invalid price band (LLM could emit 0/5/9).
    $run = $share->analysisRun;
    $result = $run->result_json;
    $result['places'][0]['price_range'] = 9;
    $run->result_json = $result;
    $run->save();

    (new ResolvePlace($share->id))->handle();

    expect(Place::sole()->price_range)->toBeNull()
        ->and($share->fresh()->status)->toBe(ShareStatus::Analyzing);
});

it('routes to review/geocode_failed when the geocoder finds nothing', function () {
    bindGeocoder(new FakeGeocoder); // nothing seeded → null
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('geocode_failed')
        ->and(Place::count())->toBe(0);
});

it('routes to review/geocode_failed when the geocode score is below the floor', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJlow', 51.5, -0.13, score: 0.3)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    expect($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->review_reason)->toBe('geocode_failed')
        ->and(Place::count())->toBe(0);
});

it('is idempotent — a second run creates no duplicate place or source', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJidem', 38.7223, -9.1393)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();
    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(1)
        ->and(PlaceSource::count())->toBe(1);
});

it('fails the share geocode_failed when the geocoder throws a transient error', function () {
    bindGeocoder(new class implements Geocoder
    {
        public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
        {
            throw new GeocodeFailed('provider down');
        }
    });
    $share = analyzingShare();

    $job = new ResolvePlace($share->id);
    try {
        $job->handle();
    } catch (GeocodeFailed $e) {
        $job->failed($e);
    }

    expect($share->fresh()->status)->toBe(ShareStatus::Failed)
        ->and($share->fresh()->failure_reason)->toBe('geocode_failed');
});

it('no-ops when the share is not analyzing', function () {
    bindGeocoder(new FakeGeocoder);
    $share = Share::factory()->create(['status' => ShareStatus::Review]);

    (new ResolvePlace($share->id))->handle();

    expect(Place::count())->toBe(0);
});

it('parks the share in review when its google_place_id matches an admin-hidden place (T-035)', function () {
    $hidden = Place::factory()->atPoint(51.5200, -0.1400)->withGooglePlaceId('ChIJhidden')->create([
        'name' => 'Lanzhou Beef Noodle House',
        'status' => PlaceStatus::Hidden->value,
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJhidden', 51.5200, -0.1400)));
    $share = analyzingShare();

    (new ResolvePlace($share->id))->handle();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Review)
        ->and($share->review_reason)->toBe('place_hidden')
        ->and(PlaceSource::where('share_id', $share->id)->exists())->toBeFalse()
        ->and($hidden->fresh()->status)->toBe(PlaceStatus::Hidden); // untouched
});
