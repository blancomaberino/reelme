<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\User;
use App\Services\Feed\PublishedShareFeed;
use App\Services\Moderation\ShareModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('unpublishes a share and drops the pin it solely fed', function () {
    $place = Place::factory()->create(['status' => PlaceStatus::Pending, 'shares_count' => 1]);
    $share = publishedShare($place);

    app(ShareModerator::class)->takeDown($share);

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Rejected)
        ->and($share->failure_reason)->toBe('admin_removed')
        ->and($share->published_place_source_id)->toBeNull()
        ->and(PlaceSource::where('share_id', $share->id)->whereNotNull('published_at')->count())->toBe(0)
        ->and($place->fresh()->status)->toBe(PlaceStatus::Removed)                       // orphaned → pin gone
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(0);
});

it('keeps a pin alive when another user also published it', function () {
    $place = Place::factory()->create(['status' => PlaceStatus::Active, 'shares_count' => 2]);
    $mine = publishedShare($place, User::factory()->create(['is_public' => true]));
    publishedShare($place, User::factory()->create(['is_public' => true])); // someone else's contribution

    app(ShareModerator::class)->takeDown($mine);

    $place->refresh();
    expect($place->status)->toBe(PlaceStatus::Active)                        // still vouched-for → stays on the map
        ->and($place->shares_count)->toBe(1)                                 // recounted, not left stale at 2
        ->and(Place::query()->publiclyVisible()->whereKey($place->id)->exists())->toBeTrue()
        ->and(app(PublishedShareFeed::class)->paginate('recent', null, 20)['items'])->toHaveCount(1); // the other card survives
});
