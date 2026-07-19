<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Feed\PublishedShareFeed;
use App\Services\Moderation\PlaceModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides a place off the map, search, and the feed at once', function () {
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($place);

    expect(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeTrue()
        ->and($place->shouldBeSearchable())->toBeTrue()
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(1);

    app(PlaceModerator::class)->takeDown([$place]);

    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Hidden)
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeFalse()
        ->and($place->shouldBeSearchable())->toBeFalse()
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(0);
});

it('never touches a merged or removed place', function () {
    $merged = Place::factory()->create(['status' => PlaceStatus::Merged]);
    $removed = Place::factory()->create(['status' => PlaceStatus::Removed]);

    app(PlaceModerator::class)->takeDown([$merged, $removed]);

    expect($merged->fresh()->status)->toBe(PlaceStatus::Merged)
        ->and($removed->fresh()->status)->toBe(PlaceStatus::Removed);
});

it('restores a hidden place to the review queue (matching the per-record Restore)', function () {
    $single = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($single);
    $double = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($double);
    publishedShare($double);

    app(PlaceModerator::class)->takeDown([$single, $double]);
    app(PlaceModerator::class)->restore([$single, $double]);

    // Both come back to Pending — a human re-reviews; a later publish can re-activate.
    expect($single->fresh()->status)->toBe(PlaceStatus::Pending)
        ->and($double->fresh()->status)->toBe(PlaceStatus::Pending);
});

it('refuses to restore a Hidden place that has lost all provenance (no ghost pins)', function () {
    // Hidden, then its only source was reprocessed away → sourceless. Restoring it
    // would put a provenance-less pin back on the public map.
    $orphan = Place::factory()->create(['status' => PlaceStatus::Active]);
    $share = publishedShare($orphan);
    app(PlaceModerator::class)->takeDown([$orphan]);           // → Hidden
    PlaceSource::where('share_id', $share->id)->delete();      // now sourceless

    app(PlaceModerator::class)->restore([$orphan]);

    expect($orphan->fresh()->status)->toBe(PlaceStatus::Hidden);  // stays hidden — not resurrected
});

it('only un-hides, leaving Removed orphans and live places untouched', function () {
    // Removed is the auto-orphan tombstone path — restore must not revive it
    // (that happens via a re-share), and it must not disturb a live place.
    $removedOrphan = Place::factory()->create(['status' => PlaceStatus::Removed]);
    $active = Place::factory()->create(['status' => PlaceStatus::Active]);

    app(PlaceModerator::class)->restore([$removedOrphan, $active]);

    expect($removedOrphan->fresh()->status)->toBe(PlaceStatus::Removed)
        ->and($active->fresh()->status)->toBe(PlaceStatus::Active);
});
