<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Geo\FakeGeocoder;
use App\Services\Places\PlacePublisher;
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

it('revives a taken-down Google-verified place to pending, not straight to active (ADR-086)', function () {
    // A moderator take-down (Removed) must re-earn the map via normal review, not
    // be undone by a single re-share off cached Google data.
    $place = Place::factory()->create([
        'status' => PlaceStatus::Removed,
        'google_place_id' => 'ChIJremoved',
        'google_rating_count' => 300,
    ]);
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing, 'user_confirmed' => false]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'published_at' => now(),
    ]);

    app(PlacePublisher::class)->recompute($place, $share, $source);

    expect($place->fresh()->status)->toBe(PlaceStatus::Pending); // revived, but back in review — not auto-activated
});

it('does not clobber a concurrent activation when recomputing off a stale place (T-087 lock)', function () {
    // A taken-down place (Removed) is re-shared by two influencers concurrently.
    // Publisher A revives + activates it (enough corroboration) → Active in the DB.
    // Publisher B holds an instance loaded while it was still Removed; with only its
    // own single source, B's revival logic lands on Pending. Pre-fix B wrote that
    // stale Pending back — downgrading A's Active. recompute() now reads the
    // authoritative status under the row lock first, so A's activation survives.
    $place = Place::factory()->create([
        'status' => PlaceStatus::Removed,
        'google_place_id' => 'ChIJconcurrent',
        'google_rating_count' => 120,
    ]);

    // Publisher B's stale in-memory instance (loaded while still Removed).
    $stale = Place::query()->findOrFail($place->id);
    expect($stale->status)->toBe(PlaceStatus::Removed);

    // A concurrent publish already revived + activated the place in the DB.
    $place->forceFill(['status' => PlaceStatus::Active])->save();

    // B's own share + a single published source — its revival alone lands on Pending.
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing, 'user_confirmed' => false]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id, 'share_id' => $share->id, 'published_at' => now(),
    ]);

    app(PlacePublisher::class)->recompute($stale, $share, $source);

    // Activation survived (status read from the locked row, not the pre-lock
    // snapshot), counters recomputed under the lock from the committed source set.
    expect($place->fresh()->status)->toBe(PlaceStatus::Active)
        ->and($place->fresh()->shares_count)->toBe(1);
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
