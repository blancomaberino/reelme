<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\Data\FetchedMedia;
use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\DownloadMedia;
use App\Models\Share;
use App\Services\Media\FfmpegRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMediaAdapter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local_media_originals');
});

function fixturePath(string $file): string
{
    return base_path("tests/Fixtures/media/{$file}");
}

/**
 * A throwaway temp copy of a media fixture — DownloadMedia treats a yt-dlp
 * localPath as a consumable temp file and unlinks it after ingest, so tests
 * must never hand it the committed fixture itself.
 */
function tempVideo(string $fixture = 'sample.mp4'): string
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'dlvid_').'.mp4';
    copy(fixturePath($fixture), $tmp);

    return $tmp;
}

function registryReturning(FetchedMedia ...$media): AdapterRegistry
{
    $registry = Mockery::mock(AdapterRegistry::class);
    $registry->shouldReceive('resolve')->andReturn([new FakeMediaAdapter($media)]);

    return $registry;
}

function fetchingShare(): Share
{
    return Share::factory()->create(['status' => ShareStatus::Fetching]);
}

it('downloads a local-path video into a video media_asset with ffprobe metadata', function () {
    $share = fetchingShare();
    $local = tempVideo();
    $media = new FetchedMedia(kind: MediaKind::Video, localPath: $local, mime: 'video/mp4');

    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));

    $asset = $share->sourcePost->mediaAssets()->where('kind', MediaKind::Video->value)->first();
    expect($asset)->not->toBeNull()
        ->and($asset->bytes)->toBe(filesize(fixturePath('sample.mp4')))
        ->and($asset->sha256)->toBe(hash_file('sha256', fixturePath('sample.mp4')))
        ->and($asset->width)->toBe(320)
        ->and($asset->height)->toBe(240)
        ->and($asset->duration_ms)->toBeGreaterThan(0)
        ->and($asset->disk)->toBe('local_media_originals');

    Storage::disk('local_media_originals')->assertExists($asset->storage_path);
    // The consumed yt-dlp temp file is cleaned (no per-share worker disk leak).
    expect(file_exists($local))->toBeFalse();
});

it('is idempotent — a re-run creates no duplicate asset', function () {
    $share = fetchingShare();

    (new DownloadMedia($share->id))->handle(registryReturning(
        new FetchedMedia(kind: MediaKind::Video, localPath: tempVideo(), mime: 'video/mp4'),
    ), app(FfmpegRunner::class));
    // A fresh temp copy for the re-run; the status/asset guard short-circuits it.
    (new DownloadMedia($share->id))->handle(registryReturning(
        new FetchedMedia(kind: MediaKind::Video, localPath: tempVideo(), mime: 'video/mp4'),
    ), app(FfmpegRunner::class));

    expect($share->sourcePost->mediaAssets()->where('kind', MediaKind::Video->value)->count())->toBe(1);
});

it('fails the share media_too_large when the streamed body exceeds the cap', function () {
    config()->set('media.max_download_bytes', 100);
    $share = fetchingShare();
    // Body far larger than the cap and a lying (small) Content-Length — the
    // chunked byte counter must still abort.
    Http::fake(['*' => Http::response(str_repeat('a', 5_000), 200, ['Content-Length' => '10'])]);
    $media = new FetchedMedia(kind: MediaKind::Video, url: 'https://cdn.test/video.mp4', mime: 'video/mp4');

    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('media_too_large')
        ->and($share->sourcePost->mediaAssets()->count())->toBe(0);
});

it('does nothing when the chain resolves no downloadable media', function () {
    $share = fetchingShare();

    (new DownloadMedia($share->id))->handle(registryReturning(), app(FfmpegRunner::class));

    expect($share->sourcePost->mediaAssets()->count())->toBe(0)
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching);
});

it('skips when the share is not in the fetching state', function () {
    $share = Share::factory()->published()->create();
    $media = new FetchedMedia(kind: MediaKind::Video, localPath: tempVideo(), mime: 'video/mp4');

    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));

    expect($share->sourcePost->mediaAssets()->count())->toBe(0);
})->group('ffmpeg');
