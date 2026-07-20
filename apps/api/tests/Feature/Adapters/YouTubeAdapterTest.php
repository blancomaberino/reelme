<?php

use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\YouTubeAdapter;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config()->set('services.youtube.api_key', null));

it('supports watch, youtu.be, shorts and embed URLs, rejects look-alikes', function () {
    $adapter = new YouTubeAdapter;

    expect($adapter->supports('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue()
        ->and($adapter->supports('https://youtu.be/dQw4w9WgXcQ'))->toBeTrue()
        ->and($adapter->supports('https://www.youtube.com/shorts/dQw4w9WgXcQ'))->toBeTrue()
        ->and($adapter->supports('https://m.youtube.com/watch?v=dQw4w9WgXcQ'))->toBeTrue()
        ->and($adapter->supports('https://www.youtube.com/embed/dQw4w9WgXcQ'))->toBeTrue()
        // No video id in the path/query — not a post.
        ->and($adapter->supports('https://www.youtube.com/@channel'))->toBeFalse()
        ->and($adapter->supports('https://youtube.com.evil.test/watch?v=x'))->toBeFalse()
        ->and($adapter->supports('https://www.tiktok.com/@x/video/1'))->toBeFalse();
});

it('uses the Data API v3 for the full description, channel, and publishedAt when a key is set', function () {
    config()->set('services.youtube.api_key', 'test-key');
    Http::fake(['googleapis.com/youtube/v3/videos*' => Http::response([
        'items' => [[
            'id' => 'dQw4w9WgXcQ',
            'snippet' => [
                'title' => 'BEST TACOS IN AUSTIN',
                'description' => "Full review of La Barbecue.\nAddress: 2027 E Cesar Chavez St.",
                'channelTitle' => 'Taco Hunter',
                'publishedAt' => '2024-03-01T12:30:00Z',
            ],
        ]],
    ])]);

    $data = (new YouTubeAdapter)->fetchMetadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ', null);

    expect($data->platform)->toBe(Platform::Youtube)
        ->and($data->externalId)->toBe('dQw4w9WgXcQ')
        ->and($data->caption)->toBe("Full review of La Barbecue.\nAddress: 2027 E Cesar Chavez St.")
        ->and($data->authorHandle)->toBe('Taco Hunter')
        ->and($data->postedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($data->postedAt?->toIso8601String())->toBe('2024-03-01T12:30:00+00:00')
        ->and($data->raw['source'])->toBe('youtube-api');

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/videos')
        && str_contains($r->url(), 'id=dQw4w9WgXcQ')
        && str_contains($r->url(), 'key=test-key'));
});

it('treats an empty Data API items array as PostUnavailable', function () {
    config()->set('services.youtube.api_key', 'test-key');
    Http::fake(['googleapis.com/youtube/v3/videos*' => Http::response(['items' => []])]);

    expect(fn () => (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null))
        ->toThrow(PostUnavailable::class);
});

it('silently falls back to keyless oEmbed when no API key is configured', function () {
    Http::fake([
        // If the Data API is hit without a key that is a bug — fail loudly.
        'googleapis.com/*' => Http::response('should not be called', 500),
        '*youtube.com/oembed*' => Http::response([
            'title' => 'BEST TACOS IN AUSTIN — La Barbecue',
            'author_name' => 'Taco Hunter',
            'author_url' => 'https://www.youtube.com/@tacohunter',
        ]),
    ]);

    $data = (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null);

    expect($data->caption)->toBe('BEST TACOS IN AUSTIN — La Barbecue')
        ->and($data->authorHandle)->toBe('tacohunter')
        ->and($data->authorDisplayName)->toBe('Taco Hunter')
        ->and($data->externalId)->toBe('dQw4w9WgXcQ')
        ->and($data->postedAt)->toBeNull()
        ->and($data->raw['source'])->toBe('youtube-oembed');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'googleapis.com'));
});

it('treats a titleless oEmbed body as PostUnavailable', function () {
    Http::fake(['*youtube.com/oembed*' => Http::response(['author_name' => 'No Title'])]);
    expect(fn () => (new YouTubeAdapter)->fetchMetadata('https://youtu.be/blank1', null))
        ->toThrow(PostUnavailable::class);
});

it('maps oEmbed transient/permanent statuses to the taxonomy', function (int $status, string $exception) {
    Http::fake(['*youtube.com/oembed*' => Http::response('', $status)]);
    expect(fn () => (new YouTubeAdapter)->fetchMetadata('https://youtu.be/gone12', null))
        ->toThrow($exception);
})->with([
    'deleted → permanent' => [404, PostUnavailable::class],
    'bad gateway → transient' => [502, FetchFailed::class],
]);

it('rejects a watch URL with no resolvable video id called directly', function () {
    // `?list=…` with no `?v=…` carries no video id → self-guard, not a crash.
    expect(fn () => (new YouTubeAdapter)->fetchMetadata('https://www.youtube.com/watch?list=PL123', null))
        ->toThrow(PostUnavailable::class);
});

it('maps a Data API connection error to FetchFailed and never requires auth', function () {
    config()->set('services.youtube.api_key', 'test-key');
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect(fn () => (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null))
        ->toThrow(FetchFailed::class);
    expect((new YouTubeAdapter)->requiresAuth())->toBeFalse();
});

it('leaves postedAt null when the Data API omits publishedAt', function () {
    config()->set('services.youtube.api_key', 'test-key');
    Http::fake(['googleapis.com/youtube/v3/videos*' => Http::response([
        'items' => [[
            'id' => 'dQw4w9WgXcQ',
            'snippet' => ['title' => 'T', 'description' => 'd', 'channelTitle' => 'c'],
        ]],
    ])]);

    $data = (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null);

    expect($data->postedAt)->toBeNull()
        ->and($data->caption)->toBe('d');
});

it('leaves postedAt null on a malformed Data API publishedAt', function () {
    config()->set('services.youtube.api_key', 'test-key');
    Http::fake(['googleapis.com/youtube/v3/videos*' => Http::response([
        'items' => [[
            'id' => 'dQw4w9WgXcQ',
            'snippet' => [
                'title' => 'T',
                'description' => 'the description',
                'channelTitle' => 'Chan',
                'publishedAt' => 'not-a-real-date',
            ],
        ]],
    ])]);

    $data = (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null);

    expect($data->postedAt)->toBeNull()
        ->and($data->caption)->toBe('the description');
});

it('falls back to author_name when the oEmbed author_url carries no @handle', function () {
    Http::fake(['*youtube.com/oembed*' => Http::response([
        'title' => 'A video',
        'author_name' => 'Taco Hunter',
        'author_url' => 'https://www.youtube.com/channel/UC1234567890',
    ])]);

    $data = (new YouTubeAdapter)->fetchMetadata('https://youtu.be/dQw4w9WgXcQ', null);

    expect($data->authorHandle)->toBe('Taco Hunter');
});

it('never yields media directly — video comes from the yt-dlp step in the chain', function () {
    $media = (new YouTubeAdapter)->fetchMedia(
        new SourcePostData(Platform::Youtube, 'dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ'),
        null,
    );
    expect($media)->toBeInstanceOf(MediaFetchResult::class)
        ->and($media->media)->toBe([]);
    // The YouTube kill switch is enforced in AdapterRegistry, not supports().
});
