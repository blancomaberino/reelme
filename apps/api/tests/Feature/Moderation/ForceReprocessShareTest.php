<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\ExtractPlaceData;
use App\Jobs\Pipeline;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceListItem;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Moderation\ForceReprocessShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('assembles a force-extract chain whose extract stage re-runs the LLM', function () {
    $jobs = Pipeline::chain(5, 'extract', forceExtract: true);

    expect($jobs[0])->toBeInstanceOf(ExtractPlaceData::class)
        ->and($jobs[0]->force)->toBeTrue();

    // Default stays cheap: reuse a prior succeeded run.
    expect(Pipeline::chain(5, 'extract')[0]->force)->toBeFalse();
});

it('clears the prior pins, resets past the terminal guard, and queues a fresh chain', function () {
    Bus::fake();

    $place = Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]);
    $share = publishedShare($place);

    expect($share->status)->toBe(ShareStatus::Published); // terminal — normal machine forbids re-run

    app(ForceReprocessShare::class)->run($share);

    $share->refresh();
    expect(PlaceSource::where('share_id', $share->id)->count())->toBe(0)
        ->and($share->status)->toBe(ShareStatus::Fetching)     // entry status for `extract`
        ->and($share->published_place_source_id)->toBeNull()
        ->and($place->fresh()->status)->toBe(PlaceStatus::Removed); // orphaned old pin dropped

    Bus::assertChained([ExtractPlaceData::class, ResolvePlace::class, PublishShare::class]);
});

it('keeps a saved place alive but re-counts it when its source is cleared', function () {
    Bus::fake();

    $place = Place::factory()->create(['status' => PlaceStatus::Active, 'shares_count' => 1]);
    $share = publishedShare($place);
    $list = PlaceList::factory()->create();
    PlaceListItem::forceCreate(['place_list_id' => $list->id, 'place_id' => $place->id, 'position' => 0]);

    app(ForceReprocessShare::class)->run($share);

    // A saver still wants it, so it is NOT tombstoned — but its counter drops to
    // the (now zero) published-source count.
    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Active)
        ->and($place->shares_count)->toBe(0);
});

it('keeps a place alive and recounts it when another user still published it', function () {
    Bus::fake();

    $place = Place::factory()->create(['status' => PlaceStatus::Active, 'shares_count' => 2]);
    $mine = publishedShare($place, User::factory()->create(['is_public' => true]));
    publishedShare($place, User::factory()->create(['is_public' => true])); // someone else's source survives

    app(ForceReprocessShare::class)->run($mine);

    // Not orphaned (a sibling source keeps it) → stays on the map, counter re-derived.
    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Active)
        ->and($place->shares_count)->toBe(1);
});

it('re-resolves and re-publishes without duplicating sources or corrupting counts', function () {
    // Drive the reprocess from `resolve` so the existing succeeded run is reused
    // (no LLM double-spend) while still exercising delete → re-resolve → re-publish.
    $place = publishTaggedShare();
    $share = Share::query()->where('published_place_source_id', '!=', null)->sole();

    expect(Place::count())->toBe(1);

    app(ForceReprocessShare::class)->run($share, fromStage: 'resolve');

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and(Place::count())->toBe(1)                                       // reused, not duplicated
        ->and(PlaceSource::where('share_id', $share->id)->whereNotNull('published_at')->count())->toBe(1);

    $revived = Place::sole();
    expect($revived->shares_count)->toBe($revived->sources()->whereNotNull('published_at')->count());
});
