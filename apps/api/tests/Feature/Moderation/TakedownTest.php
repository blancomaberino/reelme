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
use Illuminate\Support\Facades\Log;
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
        // FR-30, asserted on STATUS. `not->toBeNull()` was the original check
        // and it passes on a `Removed` tombstone — which is exactly what was
        // happening: the pin was off the map while `places_kept` claimed it
        // had been kept.
        ->and(Place::find($place->id)->status)->toBe(PlaceStatus::Pending)
        ->and($outcome['places_kept'])->toBe([$place->id])
        // It has no provenance left, so it is flagged rather than left to sit
        // on the map as a claim nothing supports.
        ->and(Place::find($place->id)->needs_admin_review)->toBeTrue()
        ->and($outcome['places_revived'])->toBe([$place->id]);
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
        // What actually reproduces the rightsholder's material does go. The
        // transcript most of all — it is a verbatim copy of their audio.
        ->and($post->caption)->toBeNull()
        ->and($post->oembed_json)->toBeNull()
        ->and($post->transcript_json)->toBeNull();
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

    $outcome = app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // Their contribution is untouched, and the pin they created is still live.
    // `toBe(Active)`, not `not->toBe(Removed)`: the loose version passes on
    // Hidden too, i.e. on a pin that has vanished from the map.
    expect($otherShare->fresh()->status)->toBe(ShareStatus::Published)
        ->and(PlaceSource::where('source_post_id', $otherPost->id)->exists())->toBeTrue()
        ->and(Place::find($place->id)->status)->toBe(PlaceStatus::Active)
        // Nothing was revived, because nothing was orphaned.
        ->and($outcome['places_revived'] ?? [])->toBe([]);
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

it('leaves an unmatched notice OPEN rather than calling it actioned', function () {
    $admin = User::factory()->admin()->create();
    $request = TakedownRequest::factory()->create(['target_url' => 'https://instagram.com/reel/unknown/']);

    $outcome = app(ProcessTakedown::class)->execute($request, $admin);
    $request->refresh();

    // Nothing was removed, so "actioned" would be a false legal record — and it
    // would drop the notice out of the queue AND hide the action button, so the
    // toast telling the admin to "match one and action it again" would point at
    // a control that is no longer there.
    expect($outcome['shares'])->toBe(0)
        ->and($request->status)->toBe(TakedownStatus::Received)
        ->and($request->status->isOpen())->toBeTrue()
        ->and($request->actioned_at)->toBeNull()
        ->and($request->actioned_by_user_id)->toBeNull()
        ->and($request->outcome_json['places_kept'])->toBe([]);
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

    $outcome = app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // A takedown is a legal obligation with a clock on it. The rows go either
    // way; the orphaned object is swept by the retention pass, which is what
    // that pass is for.
    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and(MediaAsset::find($asset->id))->toBeNull()
        // And the record says what actually happened. Counting an attempted
        // delete as a completed one would tell a rightsholder we removed a
        // file that is still sitting in the bucket — the one number in this
        // outcome that must never be optimistic.
        ->and($outcome['media'])->toBe(0)
        ->and($outcome['media_failed'])->toBe(1);
});

it('marks a DMCA removal distinguishably from a routine admin one', function () {
    $admin = User::factory()->admin()->create();
    ['post' => $post, 'share' => $share] = takedownFixture();

    app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // `failure_reason` is the only DB evidence of WHY a share was pulled.
    // Leaving it as `admin_removed` makes "which shares did we remove for
    // notice #12" answerable only by grepping logs.
    expect($share->fresh()->failure_reason)->toBe('takedown');
});

it('records the takedown in the audit log', function () {
    Log::spy();
    $admin = User::factory()->admin()->create();
    ['post' => $post] = takedownFixture();

    app(ProcessTakedown::class)->execute(TakedownRequest::factory()->forPost($post)->create(), $admin);

    // The log IS the audit trail this design justifies itself with. Without
    // this assertion the whole Log::info block could be deleted and every test
    // on the branch would stay green.
    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context) => $message === 'moderation.takedown.processed'
            && $context['result'] === 'actioned'
            && $context['admin_id'] === $admin->id
            && $context['shares'] === 1,
    );
});

it('lets only one admin action a notice', function () {
    $admin = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();
    ['post' => $post, 'share' => $share] = takedownFixture();
    $request = TakedownRequest::factory()->forPost($post)->create();

    $first = app(ProcessTakedown::class)->execute($request, $admin);

    // The second admin pressed the button before the page refreshed. Without an
    // atomic claim this runs the whole take-down again and overwrites the first
    // run's record of what was removed — competing audit outcomes for one notice.
    $repeat = app(ProcessTakedown::class)->execute($request->fresh(), $second);

    // Same outcome handed back (key order differs — it comes back through
    // JSON), and crucially the FIRST admin stays recorded as the actor.
    expect($repeat['shares'])->toBe($first['shares'])
        ->and($repeat['places_kept'])->toBe($first['places_kept'])
        ->and($request->fresh()->actioned_by_user_id)->toBe($admin->id)
        ->and($share->fresh()->status)->toBe(ShareStatus::Rejected);
});

it('reports what a previous run did rather than redoing it', function () {
    $admin = User::factory()->admin()->create();
    $request = TakedownRequest::factory()->create(['status' => TakedownStatus::Closed]);

    // A closed notice is not available to action. Handing back its recorded
    // outcome beats inventing a second answer for the same notice.
    $outcome = app(ProcessTakedown::class)->execute($request, $admin);

    expect($outcome['shares'])->toBe(0)
        ->and($request->fresh()->status)->toBe(TakedownStatus::Closed);
});
