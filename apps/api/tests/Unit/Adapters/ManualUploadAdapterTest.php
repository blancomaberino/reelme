<?php

use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\NeedsManualFallback;
use App\Adapters\ManualUploadAdapter;
use App\Enums\MediaKind;
use App\Enums\Platform;
use App\Models\MediaAsset;
use App\Models\SourcePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function manualAdapter(): ManualUploadAdapter
{
    return app(ManualUploadAdapter::class);
}

it('supports any URL and needs no auth', function () {
    expect(manualAdapter()->supports('https://anything/x'))->toBeTrue()
        ->and(manualAdapter()->requiresAuth())->toBeFalse();
});

it('throws NeedsManualFallback when there is no manual payload', function () {
    // A source_post with no screen_recording asset is not a manual payload yet.
    SourcePost::factory()->create(['url' => 'https://insta/reel/A']);

    manualAdapter()->fetchMetadata('https://insta/reel/A', null);
})->throws(NeedsManualFallback::class);

it('throws NeedsManualFallback when the post does not exist', function () {
    manualAdapter()->fetchMetadata('https://never/seen', null);
})->throws(NeedsManualFallback::class);

it('returns manual SourcePostData when a payload exists', function () {
    Storage::fake('local_media');

    $post = SourcePost::factory()->create([
        'platform' => Platform::Instagram,
        'url' => 'https://insta/reel/B',
        'caption' => 'pasted caption text',
    ]);
    MediaAsset::factory()->create([
        'source_post_id' => $post->id,
        'kind' => MediaKind::ScreenRecording,
        'disk' => 'local_media',
        'storage_path' => 'media/'.$post->id.'/original/rec.mp4',
        'mime' => 'video/mp4',
    ]);

    $data = manualAdapter()->fetchMetadata('https://insta/reel/B', null);

    expect($data)->toBeInstanceOf(SourcePostData::class)
        ->and($data->caption)->toBe('pasted caption text')
        ->and($data->platform)->toBe(Platform::Instagram)
        ->and($data->raw['source'])->toBe('manual');
});

it('returns the uploaded screen recording as media', function () {
    Storage::fake('local_media');

    $post = SourcePost::factory()->create([
        'platform' => Platform::Tiktok,
        'external_id' => 'REC123',
        'url' => 'https://tt/v/C',
    ]);
    MediaAsset::factory()->create([
        'source_post_id' => $post->id,
        'kind' => MediaKind::ScreenRecording,
        'disk' => 'local_media',
        'storage_path' => 'media/'.$post->id.'/original/rec.mp4',
        'mime' => 'video/mp4',
    ]);

    $data = new SourcePostData(platform: Platform::Tiktok, externalId: 'REC123', url: 'https://tt/v/C');
    $result = manualAdapter()->fetchMedia($data, null);

    expect($result->media)->toHaveCount(1)
        ->and($result->media[0]->kind)->toBe(MediaKind::ScreenRecording)
        ->and($result->media[0]->mime)->toBe('video/mp4')
        ->and($result->media[0]->url)->toContain('rec.mp4');
});
