<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Enums\TakedownStatus;
use App\Models\MediaAsset;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Services\Moderation\ProcessTakedown;
use Illuminate\Support\Facades\Storage;

/**
 * The takedown / DMCA flow (T-049, IR-2 / R-07 / ADR-010).
 *
 * The whole design tension is FR-30: a rightsholder is objecting to their
 * FOOTAGE, not to the existence of a restaurant. Answering a copyright
 * complaint by deleting a map pin destroys the contribution of everyone else
 * who added the same place — so the tests below are mostly about what must
 * SURVIVE, not what goes.
 */
beforeEach(function () {
    Storage::fake(config('media.disk'));
});

/**
 * A published place backed by one post, with media on disk.
 *
 * @return array{post: SourcePost, share: Share, place: Place, source: PlaceSource, asset: MediaAsset}
 */
function takedownFixture(): array
{
    $post = SourcePost::factory()->create(['caption' => 'my copyrighted words']);
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    $share = Share::factory()->published()->create(['source_post_id' => $post->id]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $post->id,
        'published_at' => now(),
    ]);
    $asset = MediaAsset::factory()->create(['source_post_id' => $post->id]);
    Storage::disk(config('media.disk'))->put($asset->storage_path, 'their footage');

    return compact('post', 'share', 'place', 'source', 'asset');
}

it('unpublishes the share, drops the source, deletes the media — and keeps the place', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post, 'share' => $share, 'place' => $place, 'source' => $source, 'asset' => $asset] = takedownFixture();
    $request = TakedownRequest::factory()->forPost($post)->create();

    $outcome = app(ProcessTakedown::class)->execute($request, $admin);

    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and(PlaceSource::find($source->id))->toBeNull()
        ->and(MediaAsset::find($asset->id))->toBeNull()
        ->and(Storage::disk(config('media.disk'))->exists($asset->storage_path))->toBeFalse()
        // FR-30. The restaurant is not the rightsholder's material, and other
        // people may have contributed it independently.
        ->and(Place::find($place->id))->not->toBeNull()
        ->and($outcome['places_kept'])->toBe([$place->id]);
});

it('keeps the source_post row while scrubbing what it reproduces', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post] = takedownFixture();
    $request = TakedownRequest::factory()->forPost($post)->create();

    app(ProcessTakedown::class)->execute($request, $admin);

    $post->refresh();

    // The row survives: other analytics reference it, and deleting it would
    // cascade its media away silently and erase the only evidence a takedown
    // ever happened here — the thing a counter-notice asks about.
    expect($post->exists)->toBeTrue()
        ->and($post->url)->not->toBeNull()
        // What actually reproduces the rightsholder's material does go.
        ->and($post->caption)->toBeNull()
        ->and($post->oembed_json)->toBeNull();
});

it('takes down every share citing the post, not just the first', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post, 'share' => $first] = takedownFixture();
    $second = Share::factory()->published()->create(['source_post_id' => $post->id]);
    $request = TakedownRequest::factory()->forPost($post)->create();

    $outcome = app(ProcessTakedown::class)->execute($request, $admin);

    // A popular reel is shared by several people. Leaving one live would mean
    // the notice was answered on paper and not in the product.
    expect($outcome['shares'])->toBe(2)
        ->and($first->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and($second->fresh()->status)->toBe(ShareStatus::Rejected);
});

it('leaves a place standing when another source still supports it', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post, 'place' => $place] = takedownFixture();

    // A second, unrelated contributor found the same restaurant.
    $otherPost = SourcePost::factory()->create();
    $otherShare = Share::factory()->published()->create(['source_post_id' => $otherPost->id]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $otherShare->id,
        'source_post_id' => $otherPost->id,
        'published_at' => now(),
    ]);

    app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // Their contribution is untouched, and the pin they created is still live.
    expect($otherShare->fresh()->status)->toBe(ShareStatus::Published)
        ->and(PlaceSource::where('source_post_id', $otherPost->id)->exists())->toBeTrue()
        ->and(Place::find($place->id)->status)->not->toBe(PlaceStatus::Removed);
});

it('records what it did, for the response letter', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post] = takedownFixture();
    $request = TakedownRequest::factory()->forPost($post)->create();

    app(ProcessTakedown::class)->execute($request, $admin);

    $request->refresh();

    // "We removed it" is not an answer a rightsholder or a court can check.
    expect($request->status)->toBe(TakedownStatus::Actioned)
        ->and($request->actioned_by_user_id)->toBe($admin->id)
        ->and($request->actioned_at)->not->toBeNull()
        ->and($request->outcome_json['shares'])->toBe(1)
        ->and($request->outcome_json['media'])->toBe(1);
});

it('logs and closes a notice nobody has matched to a post yet', function () {
    $admin = User::factory()->admin()->create();
    $request = TakedownRequest::factory()->create(['target_url' => 'https://instagram.com/reel/unknown/']);

    $outcome = app(ProcessTakedown::class)->execute($request, $admin);

    // A notice that arrives as a bare URL still needs an answer. Refusing to
    // process it until someone matches the row is how notices get lost.
    expect($outcome['shares'])->toBe(0)
        ->and($request->fresh()->status)->toBe(TakedownStatus::Actioned)
        ->and($request->fresh()->outcome_json['places_kept'])->toBe([]);
});

it('matches a bare URL to its post so ops can log first and match later', function () {
    $post = SourcePost::factory()->create(['url' => 'https://www.instagram.com/reel/abc123/']);

    expect(app(ProcessTakedown::class)->matchByUrl('  https://www.instagram.com/reel/abc123/  ')?->id)
        ->toBe($post->id)
        ->and(app(ProcessTakedown::class)->matchByUrl('https://example.com/nope'))->toBeNull();
});

it('finishes the takedown even when the bucket refuses', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post, 'share' => $share, 'asset' => $asset] = takedownFixture();

    Storage::shouldReceive('disk')->andThrow(new RuntimeException('bucket down'));

    app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // A takedown is a legal obligation with a clock on it. The rows go either
    // way; the orphaned object is swept by the retention pass, which is what
    // that pass is for.
    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and(MediaAsset::find($asset->id))->toBeNull();
});
