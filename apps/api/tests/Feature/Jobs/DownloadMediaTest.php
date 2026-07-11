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
    $media = new FetchedMedia(kind: MediaKind::Video, localPath: fixturePath('sample.mp4'), mime: 'video/mp4');

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
});

it('is idempotent — a re-run creates no duplicate asset', function () {
    $share = fetchingShare();
    $media = new FetchedMedia(kind: MediaKind::Video, localPath: fixturePath('sample.mp4'), mime: 'video/mp4');

    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));
    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));

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
    $media = new FetchedMedia(kind: MediaKind::Video, localPath: fixturePath('sample.mp4'), mime: 'video/mp4');

    (new DownloadMedia($share->id))->handle(registryReturning($media), app(FfmpegRunner::class));

    expect($share->sourcePost->mediaAssets()->count())->toBe(0);
})->group('ffmpeg');
