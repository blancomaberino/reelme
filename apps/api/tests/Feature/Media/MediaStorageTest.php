<?php

use App\Services\Media\MediaPaths;
use App\Services\Media\MediaUrlService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

it('strips separators and junk from the original extension', function () {
    // A caller that derived $ext from a filename/mime must not be able to inject a
    // path separator (or anything but [a-z0-9]) into the object key.
    expect(MediaPaths::original('shr_1', 'abc', 'mp4/../../etc/passwd'))
        ->toBe('media/shr_1/original/abc.mp4etcpasswd')
        ->and(MediaPaths::original('shr_1', 'abc', '.MOV'))->toBe('media/shr_1/original/abc.MOV')
        ->and(MediaPaths::original('shr_1', 'abc', 'jpg?x=1'))->toBe('media/shr_1/original/abc.jpgx1');
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

    $this->call('PUT', $signed['url'], content: 'video-bytes', server: ['CONTENT_LENGTH' => '11'])
        ->assertNoContent();

    Storage::disk('local_media')->assertExists('media/shr_1/original/clip.mp4');
    expect(Storage::disk('local_media')->get('media/shr_1/original/clip.mp4'))->toBe('video-bytes');
});

it('rejects a local upload with no Content-Length (411)', function () {
    Storage::fake('local_media');

    $signed = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/clip.mp4', 'local_media');

    // No CONTENT_LENGTH server param → the length is absent → refused.
    $this->call('PUT', $signed['url'], content: 'video-bytes')->assertStatus(411);

    Storage::disk('local_media')->assertMissing('media/shr_1/original/clip.mp4');
});

it('rejects a local upload whose declared length exceeds the cap (413)', function () {
    config(['media.max_upload_bytes' => 8]);
    Storage::fake('local_media');

    $signed = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/clip.mp4', 'local_media');

    $this->call('PUT', $signed['url'], content: 'video-bytes', server: ['CONTENT_LENGTH' => '11'])
        ->assertStatus(413);

    Storage::disk('local_media')->assertMissing('media/shr_1/original/clip.mp4');
});

it('caps the bytes written even when Content-Length under-reports the body (413)', function () {
    // The header-only check was bypassable: a client can declare a small length and
    // stream a larger body. The stream cap on the received bytes must catch it.
    config(['media.max_upload_bytes' => 4]);
    Storage::fake('local_media');

    $signed = app(MediaUrlService::class)->temporaryUploadUrl('media/shr_1/original/clip.mp4', 'local_media');

    $this->call('PUT', $signed['url'], content: 'video-bytes', server: ['CONTENT_LENGTH' => '4'])
        ->assertStatus(413);

    Storage::disk('local_media')->assertMissing('media/shr_1/original/clip.mp4');
});

it('rejects the local upload route without a valid signature', function () {
    $this->put('/api/media/upload?disk=local_media&path=media/x/y.mp4')
        ->assertForbidden();
});

it('rejects a signed upload targeting a non-media disk (e.g. public)', function () {
    // Even a validly-signed URL may only target the configured media disks —
    // never the web-served `public` disk.
    $url = URL::temporarySignedRoute('media.upload', now()->addMinutes(5), [
        'disk' => 'public',
        'path' => 'evil.html',
    ]);

    $this->call('PUT', $url, content: '<script>')->assertNotFound();
});

it('rejects a signed upload with a traversal path', function () {
    $url = URL::temporarySignedRoute('media.upload', now()->addMinutes(5), [
        'disk' => 'local_media',
        'path' => '../../secret',
    ]);

    $this->call('PUT', $url, content: 'x')->assertStatus(422);
});
