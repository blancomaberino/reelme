<?php

use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\TikTokAdapter;
use App\Enums\Platform;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('supports full and shortlink tiktok URLs, rejects look-alikes', function () {
    $adapter = new TikTokAdapter;

    expect($adapter->supports('https://www.tiktok.com/@chef/video/7300000000000000000'))->toBeTrue()
        ->and($adapter->supports('https://vm.tiktok.com/ZMabc123/'))->toBeTrue()
        ->and($adapter->supports('https://vt.tiktok.com/ZMabc123/'))->toBeTrue()
        ->and($adapter->supports('https://tiktok.com/t/ZMabc123/'))->toBeTrue()
        ->and($adapter->supports('https://tiktok.com.evil.test/@x/video/1'))->toBeFalse()
        ->and($adapter->supports('https://www.youtube.com/watch?v=abc123'))->toBeFalse();
});

it('maps a TikTok oEmbed response to caption, unique-id handle, and numeric id', function () {
    Http::fake(['*tiktok.com/oembed*' => Http::response([
        'title' => 'best birria in LA 🌮 #tacos',
        'author_unique_id' => 'labirriera',
        'author_name' => 'La Birriera',
        'thumbnail_url' => 'https://p16.tiktok.test/thumb.jpg',
    ])]);

    $data = (new TikTokAdapter)->fetchMetadata('https://www.tiktok.com/@labirriera/video/7300000000000000123', null);

    expect($data->platform)->toBe(Platform::Tiktok)
        ->and($data->externalId)->toBe('7300000000000000123')
        ->and($data->caption)->toBe('best birria in LA 🌮 #tacos')
        ->and($data->authorHandle)->toBe('labirriera')
        ->and($data->authorDisplayName)->toBe('La Birriera')
        ->and($data->raw['source'])->toBe('tiktok-oembed')
        ->and($data->raw['thumbnail_url'])->toBe('https://p16.tiktok.test/thumb.jpg');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.tiktok.com/oembed')
        && $r->hasHeader('User-Agent'));
});

it('leaves the caption null when TikTok returns an empty title, and hashes shortlink ids', function () {
    Http::fake(['*tiktok.com/oembed*' => Http::response([
        'title' => '',
        'author_unique_id' => 'silentchef',
    ])]);

    // A shortlink carries no numeric id → a stable 24-char hash, not a crash.
    $data = (new TikTokAdapter)->fetchMetadata('https://vm.tiktok.com/ZMabc123/', null);

    expect($data->caption)->toBeNull()
        ->and($data->authorHandle)->toBe('silentchef')
        ->and($data->externalId)->toHaveLength(24)
        ->and($data->externalId)->toBe(substr(sha1('https://vm.tiktok.com/ZMabc123/'), 0, 24));
});

it('maps permanent vs transient failures to the taxonomy', function (int $status, string $exception) {
    Http::fake(['*tiktok.com/oembed*' => Http::response('', $status)]);
    expect(fn () => (new TikTokAdapter)->fetchMetadata('https://www.tiktok.com/@x/video/1', null))
        ->toThrow($exception);
})->with([
    'deleted/private → permanent' => [410, PostUnavailable::class],
    'server error → transient' => [500, FetchFailed::class],
]);

it('degrades to manual-only when the TikTok kill switch is off, and never yields media', function () {
    config()->set('ingestion.platforms.tiktok.enabled', false);
    expect((new TikTokAdapter)->supports('https://www.tiktok.com/@x/video/1'))->toBeFalse();

    $media = (new TikTokAdapter)->fetchMedia(
        new SourcePostData(Platform::Tiktok, '1', 'https://www.tiktok.com/@x/video/1'),
        null,
    );
    expect($media)->toBeInstanceOf(MediaFetchResult::class)
        ->and($media->media)->toBe([]);
});
