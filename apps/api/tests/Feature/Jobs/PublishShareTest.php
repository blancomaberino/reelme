<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Geo\FakeGeocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Reuses the global helpers analyzingShare(), geoResult(), bindGeocoder() from
// tests/Feature/Jobs/ResolvePlaceTest.php.

it('publishes a resolved share and leaves a single unverified source pending', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJpub', 51.5, -0.13)));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();

    (new PublishShare($share->id))->handle();

    $share->refresh();
    $place = Place::sole();
    $source = PlaceSource::sole();

    expect($share->status)->toBe(ShareStatus::Published)
        ->and($share->published_at)->not->toBeNull()
        ->and($share->published_place_source_id)->toBe($source->id)
        ->and($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->shares_count)->toBe(1)
        ->and((float) $place->avg_extraction_confidence)->toBe(0.9);
});

it('activates a place once it has a second independent source', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJtwo', 51.5, -0.13)));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();
    $place = Place::sole();
    // A prior independent share already contributed a PUBLISHED source to this place.
    PlaceSource::factory()->create(['place_id' => $place->id, 'published_at' => now()]);

    (new PublishShare($share->id))->handle();

    expect($place->fresh()->status)->toBe(PlaceStatus::Active)
        ->and($place->fresh()->shares_count)->toBe(2);
});

it('activates a place when the user confirmed in review, even with one source', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJconf', 51.5, -0.13)));
    $share = analyzingShare();
    $share->user_confirmed = true;
    $share->save();
    (new ResolvePlace($share->id))->handle();

    (new PublishShare($share->id))->handle();

    $place = Place::sole();
    expect($place->status)->toBe(PlaceStatus::Active)
        ->and($place->shares_count)->toBe(1);
});

it('activates a Google-verified place on its first source (ADR-086)', function () {
    // Resolved to a real Google Places establishment with 80 reviews — third-party
    // proof it exists, so it activates without waiting for a second share.
    bindGeocoder((new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        geoResult('ChIJverified', 51.5, -0.13, 0.9, 'Lanzhou Beef Noodle House', 4.6, 80),
    ));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();

    (new PublishShare($share->id))->handle();

    $place = Place::sole();
    expect($place->status)->toBe(PlaceStatus::Active)
        ->and($place->shares_count)->toBe(1)
        ->and($place->google_rating_count)->toBe(80);
});

it('leaves a Google-matched place with zero reviews pending (thin match)', function () {
    // A google_place_id but no reviews (a thin / address-only match) is not proof
    // of a real establishment — stays pending until a second source or a human.
    bindGeocoder((new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        geoResult('ChIJthin', 51.5, -0.13, 0.9, 'Lanzhou Beef Noodle House', null, 0),
    ));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();

    (new PublishShare($share->id))->handle();

    $place = Place::sole();
    expect($place->google_place_id)->not->toBeNull()
        ->and($place->status)->toBe(PlaceStatus::Pending);
});

it('is idempotent across redelivery — publishing twice keeps counts stable', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJidem2', 51.5, -0.13)));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();

    (new PublishShare($share->id))->handle();
    (new PublishShare($share->id))->handle();

    expect(Place::sole()->shares_count)->toBe(1)
        ->and(PlaceSource::count())->toBe(1)
        ->and($share->fresh()->status)->toBe(ShareStatus::Published);
});

it('no-ops when the share has no place_source (resolve parked it to review)', function () {
    $share = analyzingShare();

    (new PublishShare($share->id))->handle();

    expect($share->fresh()->status)->toBe(ShareStatus::Analyzing)
        ->and(Place::count())->toBe(0);
});
