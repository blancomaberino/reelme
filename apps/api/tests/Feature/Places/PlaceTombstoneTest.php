<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceList;
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
