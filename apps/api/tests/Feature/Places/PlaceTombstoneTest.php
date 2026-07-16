<?php

use App\Enums\PlaceStatus;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceList;
use App\Services\Geo\FakeGeocoder;
use App\Services\Places\PlacePublisher;

/**
 * Orphaned-place tombstoning + revival (T-073). A place left with no published
 * source and saved to no list is a provenance-less "ghost pin" — it is marked
 * {@see PlaceStatus::Removed} so it drops off every public/matchable surface,
 * and a later re-share revives it via {@see PlacePublisher::recompute()}.
 */
it('tombstones a sourceless, unsaved place', function () {
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    expect($place->tombstoneIfOrphaned())->toBeTrue()
        ->and($place->fresh()->status)->toBe(PlaceStatus::Removed);
});

it('is a no-op while the place still has a published source', function () {
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    publishedShare($place);

    expect($place->tombstoneIfOrphaned())->toBeFalse()
        ->and($place->fresh()->status)->toBe(PlaceStatus::Active);
});

it('is a no-op while the place is still saved to any list', function () {
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    PlaceList::factory()->create()->items()->create(['place_id' => $place->id, 'position' => 1]);

    expect($place->tombstoneIfOrphaned())->toBeFalse()
        ->and($place->fresh()->status)->toBe(PlaceStatus::Active);
});

it('never overrides a Merged or Hidden place', function (PlaceStatus $status) {
    $place = Place::factory()->atPoint(0, 0)->create(['status' => $status]);

    expect($place->tombstoneIfOrphaned())->toBeFalse()
        ->and($place->fresh()->status)->toBe($status);
})->with([
    'merged' => [PlaceStatus::Merged],
    'hidden' => [PlaceStatus::Hidden],
]);

it('revives a Removed tombstone back onto the map when re-shared', function () {
    // A place that was tombstoned after its last contributor fully removed it.
    $place = Place::factory()->atPoint(0, 0)->create(['status' => PlaceStatus::Removed, 'shares_count' => 0]);
    expect(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeFalse();

    // Re-shared: a fresh published source lands and the publisher recomputes.
    $share = publishedShare($place);
    $source = $place->sources()->firstOrFail();
    app(PlacePublisher::class)->recompute($place->fresh(), $share, $source);

    // Revived to the unverified baseline and back on every public surface.
    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Pending)
        ->and($place->shares_count)->toBe(1)
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeTrue();
});

it('revives a tombstone end-to-end through the resolve → publish pipeline', function () {
    // A place tombstoned after a full-remove, still carrying its google_place_id.
    $place = Place::factory()->atPoint(51.5, -0.13)->withGooglePlaceId('ChIJrevive')
        ->create(['status' => PlaceStatus::Removed, 'shares_count' => 0]);
    expect(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeFalse();

    // Re-shared: the geocoder returns the SAME google_place_id, so the resolver's
    // exact-id match attaches to the tombstone (never creates a duplicate) and
    // publishing revives it — the whole path, not just recompute() in isolation.
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJrevive', 51.5, -0.13)));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();
    (new PublishShare($share->id))->handle();

    expect(Place::count())->toBe(1) // reused the tombstone, no duplicate
        ->and($place->fresh()->status)->toBe(PlaceStatus::Pending)
        ->and($place->fresh()->shares_count)->toBe(1)
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeTrue();
});
