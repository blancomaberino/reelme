<?php

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Analyze-then-delete (T-050 / ADR-010, R-07) and the raw-payload window (NFR-11).
 *
 * The risk this command exists for grows silently: every share adds another
 * copy of somebody else's video, and nothing in the product ever complains
 * about having too many. So the tests here are mostly about the boundary —
 * what it must NOT delete — because an over-eager sweep blanks live place cards
 * and an under-eager one is the copyright exposure itself.
 */
/**
 * A finished share whose original is $hours old.
 */
function retentionFixture(int $hours, ShareStatus $status = ShareStatus::Published, MediaKind $kind = MediaKind::Video): MediaAsset
{
    $post = SourcePost::factory()->create();
    Share::factory()->create(['source_post_id' => $post->id, 'status' => $status]);

    $asset = MediaAsset::factory()->create([
        'source_post_id' => $post->id,
        'kind' => $kind,
        'created_at' => now()->subHours($hours),
    ]);

    Storage::disk(config('media.disk'))->put($asset->storage_path, 'bytes');

    return $asset;
}

beforeEach(function () {
    Storage::fake(config('media.disk'));
    config(['media.retention.original_hours' => 72, 'media.retention.in_flight_ceiling_hours' => 168]);
});

it('deletes an analysed original once past the window', function () {
    $asset = retentionFixture(hours: 80);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    expect(MediaAsset::find($asset->id))->toBeNull()
        ->and(Storage::disk(config('media.disk'))->exists($asset->storage_path))->toBeFalse();
});

it('keeps an original that is still inside the window', function () {
    $asset = retentionFixture(hours: 12);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    expect(MediaAsset::find($asset->id))->not->toBeNull();
});

it('keeps keyframes and thumbnails forever', function () {
    $keyframe = retentionFixture(hours: 500, kind: MediaKind::Keyframe);
    $thumb = retentionFixture(hours: 500, kind: MediaKind::Thumbnail);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    // These are OUR derived work and they are what the product renders.
    // Deleting one blanks a place card that is live on the map — the most
    // visible way this command could go wrong.
    expect(MediaAsset::find($keyframe->id))->not->toBeNull()
        ->and(MediaAsset::find($thumb->id))->not->toBeNull()
        ->and(Storage::disk(config('media.disk'))->exists($keyframe->storage_path))->toBeTrue();
});

it('keeps an original whose share is still mid-pipeline', function () {
    $asset = retentionFixture(hours: 80, status: ShareStatus::Analyzing);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    // A retry has to have something to re-read. Deleting here would strand the
    // share with no path to completion except a re-fetch it never asks for.
    expect(MediaAsset::find($asset->id))->not->toBeNull();
});

it('deletes an in-flight original once past the hard ceiling', function () {
    $asset = retentionFixture(hours: 200, status: ShareStatus::Analyzing);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    // Without a ceiling, one permanently wedged share pins a copy of somebody
    // else's video forever — and "stuck" is exactly the state nobody notices.
    expect(MediaAsset::find($asset->id))->toBeNull();
});

it('keeps an original while ANY share of that post is still running', function () {
    $asset = retentionFixture(hours: 80, status: ShareStatus::Published);
    Share::factory()->create(['source_post_id' => $asset->source_post_id, 'status' => ShareStatus::Analyzing]);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    // One finished share does not mean the file is finished with: source_posts
    // are shared between users, and the other person's analysis is still going.
    expect(MediaAsset::find($asset->id))->not->toBeNull();
});

it('keeps the object when another row still points at the same bytes', function () {
    $asset = retentionFixture(hours: 80);
    $twin = MediaAsset::factory()->create([
        'sha256' => $asset->sha256,
        'storage_path' => $asset->storage_path,
        'created_at' => now(),
    ]);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();

    // A repost resolves to the same bytes. Deleting the object out from under a
    // live row would break a place that is staying.
    expect(MediaAsset::find($asset->id))->toBeNull()
        ->and(MediaAsset::find($twin->id))->not->toBeNull()
        ->and(Storage::disk(config('media.disk'))->exists($asset->storage_path))->toBeTrue();
});

it('is idempotent — a second run is a no-op', function () {
    retentionFixture(hours: 80);

    $this->artisan('reelmap:media:prune-originals')->assertSuccessful();
    $this->artisan('reelmap:media:prune-originals')
        ->expectsOutputToContain('Deleted 0 original asset(s)')
        ->assertSuccessful();
});

it('reports without deleting on a dry run', function () {
    $asset = retentionFixture(hours: 80);

    $this->artisan('reelmap:media:prune-originals --dry-run')->assertSuccessful();

    expect(MediaAsset::find($asset->id))->not->toBeNull();
});

it('clears raw provider payloads past their window and keeps the transcript', function () {
    $old = SourcePost::factory()->create([
        'oembed_json' => ['author_name' => 'someone'],
        'transcript_json' => ['text' => 'ours'],
        'fetched_at' => now()->subDays(120),
    ]);
    $recent = SourcePost::factory()->create([
        'oembed_json' => ['author_name' => 'someone'],
        'fetched_at' => now()->subDays(5),
    ]);

    $this->artisan('reelmap:sources:prune-payloads')->assertSuccessful();

    $oldRow = DB::table('source_posts')->find($old->id);

    expect($oldRow->oembed_json)->toBeNull()
        // Derived work we produced, on a different clock entirely.
        ->and($oldRow->transcript_json)->not->toBeNull()
        // The row itself stays: it is the identity of the post, and live places
        // cite it.
        ->and($oldRow->url)->toBe($old->url)
        ->and(DB::table('source_posts')->find($recent->id)->oembed_json)->not->toBeNull();
});

it('ages a payload by its fetch, not by when the row was created', function () {
    $post = SourcePost::factory()->create([
        'oembed_json' => ['author_name' => 'someone'],
        'created_at' => now()->subDays(200),
        // Re-fetched last week: the PAYLOAD is a week old, whatever the row says.
        'fetched_at' => now()->subDays(7),
    ]);

    $this->artisan('reelmap:sources:prune-payloads')->assertSuccessful();

    expect(DB::table('source_posts')->find($post->id)->oembed_json)->not->toBeNull();
});

it('sweeps expired data-export archives and keeps fresh ones', function () {
    config(['gdpr.export_retention_days' => 7]);
    $disk = Storage::disk(config('media.disk'));

    $disk->put('exports/1/old.zip', 'archive');
    $disk->put('exports/1/fresh.zip', 'archive');
    // Backdate the mtime — the sweep keys on the file, not on a DB row.
    touch($disk->path('exports/1/old.zip'), now()->subDays(30)->getTimestamp());

    $this->artisan('reelmap:gdpr:prune-exports')->assertSuccessful();

    expect($disk->exists('exports/1/old.zip'))->toBeFalse()
        ->and($disk->exists('exports/1/fresh.zip'))->toBeTrue();
});
