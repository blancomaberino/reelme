<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Services\Feed\PublishedShareFeed;
use App\Services\Moderation\PlaceModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('pulls a taken-down place off the map, search, and the feed at once', function () {
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($place);

    expect(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeTrue()
        ->and($place->shouldBeSearchable())->toBeTrue()
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(1);

    app(PlaceModerator::class)->takeDown([$place]);

    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Removed)
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeFalse()
        ->and($place->shouldBeSearchable())->toBeFalse()
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(0);
});

it('never touches a merged or hidden place', function () {
    $merged = Place::factory()->create(['status' => PlaceStatus::Merged]);
    $hidden = Place::factory()->create(['status' => PlaceStatus::Hidden]);

    app(PlaceModerator::class)->takeDown([$merged, $hidden]);

    expect($merged->fresh()->status)->toBe(PlaceStatus::Merged)
        ->and($hidden->fresh()->status)->toBe(PlaceStatus::Hidden);
});

it('restores a removed place to its natural status from remaining sources', function () {
    $single = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($single);
    $double = Place::factory()->create(['status' => PlaceStatus::Active]);
    publishedShare($double);
    publishedShare($double);

    app(PlaceModerator::class)->takeDown([$single, $double]);
    app(PlaceModerator::class)->restore([$single, $double]);

    expect($single->fresh()->status)->toBe(PlaceStatus::Pending)  // one source → unverified baseline
        ->and($double->fresh()->status)->toBe(PlaceStatus::Active); // two sources → confirmed
});

it('only reverses a take-down, leaving live places untouched', function () {
    $active = Place::factory()->create(['status' => PlaceStatus::Active]);

    app(PlaceModerator::class)->restore([$active]);

    expect($active->fresh()->status)->toBe(PlaceStatus::Active);
});
