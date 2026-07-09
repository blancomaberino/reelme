<?php

use App\Services\Media\MediaPaths;
use App\Services\Media\MediaUrlService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

// --- Config ---

it('configures the media disks per ADR-010', function () {
    expect(config('filesystems.disks.media.driver'))->toBe('s3')
        ->and(config('filesystems.disks.media.throw'))->toBeTrue()
        ->and(config('filesystems.disks.media.root'))->toBe('derived')
        ->and(config('filesystems.disks.media_originals.driver'))->toBe('s3')
        ->and(config('filesystems.disks.media_originals.throw'))->toBeTrue()
        ->and(config('filesystems.disks.media_originals.root'))->toBe('originals');
});

// --- Path conventions (T-017 relies on these) ---

it('builds canonical media paths', function () {
    expect(MediaPaths::original('shr_1', 'abc123', 'mp4'))->toBe('media/shr_1/original/abc123.mp4')
        ->and(MediaPaths::original('shr_1', 'abc123', '.mov'))->toBe('media/shr_1/original/abc123.mov')
        ->and(MediaPaths::frame('shr_1', 0, 1200))->toBe('media/shr_1/frames/frame_0_1200.jpg')
        ->and(MediaPaths::thumbnail('shr_1'))->toBe('media/shr_1/thumb.jpg')
        ->and(MediaPaths::audio('shr_1'))->toBe('media/shr_1/audio.wav');
});

// --- Storage round-trip (no network) ---

it('round-trips media through the configured disk', function () {
    Storage::fake('local_media');

    $frame = MediaPaths::frame('shr_1', 2, 500);
    Storage::disk('local_media')->put($frame, 'jpeg-bytes');

    Storage::disk('local_media')->assertExists($frame);
    expect(Storage::disk('local_media')->get($frame))->toBe('jpeg-bytes');
});

// --- Signed URLs ---

it('signs a temporary read URL on local disks', function () {
    $url = app(MediaUrlService::class)->temporaryUrl(MediaPaths::thumbnail('shr_1'), 'local_media');

    expect($url)->toContain('signature=');
});

it('returns a signed local route for uploads on local disks', function () {
    $result = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/abc.mp4', 'local_media');

    expect($result['method'])->toBe('PUT')
        ->and($result['url'])->toContain('/media/upload')
        ->and($result['url'])->toContain('signature=');
});

it('uses a native presigned upload on s3 disks', function () {
    // config('filesystems.disks.media.driver') is 's3', so the s3 branch runs.
    $fake = Mockery::mock(Filesystem::class);
    $fake->shouldReceive('temporaryUploadUrl')->once()
        ->andReturn(['url' => 'https://r2.example/put', 'headers' => ['x-amz-acl' => 'private']]);
    Storage::shouldReceive('disk')->with('media')->andReturn($fake);

    $result = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/abc.mp4', 'media');

    expect($result)->toBe([
        'url' => 'https://r2.example/put',
        'headers' => ['x-amz-acl' => 'private'],
        'method' => 'PUT',
    ]);
});

// --- Local upload route end-to-end ---

it('stores a file via the signed local upload route', function () {
    Storage::fake('local_media');

    $signed = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/clip.mp4', 'local_media');

    $this->call('PUT', $signed['url'], content: 'video-bytes')->assertOk();

    Storage::disk('local_media')->assertExists('media/shr_1/original/clip.mp4');
});

it('rejects the local upload route without a valid signature', function () {
    $this->put('/api/media/upload?disk=local_media&path=media/x/y.mp4')
        ->assertForbidden();
});
