<?php

use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\XAdapter;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('supports x.com/twitter.com status URLs, rejects non-status and look-alikes', function () {
    $adapter = new XAdapter;

    expect($adapter->supports('https://x.com/foodguy/status/1790000000000000001'))->toBeTrue()
        ->and($adapter->supports('https://twitter.com/foodguy/status/1790000000000000001'))->toBeTrue()
        ->and($adapter->supports('https://mobile.twitter.com/u/status/17900'))->toBeTrue()
        // Profile/home URLs carry no status id — not a post.
        ->and($adapter->supports('https://x.com/foodguy'))->toBeFalse()
        ->and($adapter->supports('https://x.com/home'))->toBeFalse()
        // Look-alike host must never be classified as X.
        ->and($adapter->supports('https://x.com.evil.test/u/status/1'))->toBeFalse()
        ->and($adapter->supports('https://www.instagram.com/reel/x/'))->toBeFalse();
});

it('parses caption from the oEmbed blockquote, handle from author_url, and status id', function () {
    Http::fake(['publish.x.com/oembed*' => Http::response([
        'html' => '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">'
            .'Best tacos in Austin &amp; beyond 🌮 at <a href="https://x.com/labarbecue">@labarbecue</a>'
            .'<br>Go early!</p>&mdash; Food Guy (@foodguy) <a href="https://x.com/foodguy/status/1">Mar 1, 2024</a></blockquote>',
        'author_name' => 'Food Guy',
        'author_url' => 'https://twitter.com/foodguy',
    ])]);

    $data = (new XAdapter)->fetchMetadata('https://x.com/foodguy/status/1790000000000000001', null);

    expect($data->platform)->toBe(Platform::X)
        ->and($data->externalId)->toBe('1790000000000000001')
        // <br> → newline preserved; entities decoded; links kept as their text.
        ->and($data->caption)->toBe("Best tacos in Austin & beyond 🌮 at @labarbecue\nGo early!")
        // The "— Food Guy (@foodguy) Mar 1, 2024" attribution sits OUTSIDE the
        // <p>, so it must not bleed into the caption.
        ->and($data->caption)->not->toContain('Food Guy (@foodguy)')
        ->and($data->authorHandle)->toBe('foodguy')
        ->and($data->authorDisplayName)->toBe('Food Guy')
        ->and($data->raw['source'])->toBe('x-oembed');

    // The URL is a query param on the hardcoded endpoint (SSRF boundary), UA sent.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://publish.x.com/oembed')
        && str_contains(urldecode($r->url()), 'url=https://x.com/foodguy/status/1790000000000000001')
        && $r->hasHeader('User-Agent'));
});

it('maps 404/410 to PostUnavailable and 5xx to FetchFailed (advance the chain)', function (int $status, string $exception) {
    Http::fake(['publish.x.com/oembed*' => Http::response('', $status)]);
    expect(fn () => (new XAdapter)->fetchMetadata('https://x.com/u/status/1', null))
        ->toThrow($exception);
})->with([
    'deleted → permanent' => [404, PostUnavailable::class],
    'gone → permanent' => [410, PostUnavailable::class],
    'unstable endpoint → transient' => [503, FetchFailed::class],
]);

it('releases with a Retry-After backoff on a 429 rate-limit', function () {
    Http::fake(['publish.x.com/oembed*' => Http::response('', 429, ['Retry-After' => '42'])]);

    try {
        (new XAdapter)->fetchMetadata('https://x.com/u/status/1', null);
        $this->fail('expected FetchFailed');
    } catch (FetchFailed $e) {
        expect($e->retryAfter)->toBe(42)
            ->and($e->failureCode())->toBe('fetch_unavailable');
    }
});

it('rejects a non-status URL called directly and never requires auth', function () {
    // supports() gates this in the chain, but the adapter must still self-guard.
    expect(fn () => (new XAdapter)->fetchMetadata('https://x.com/foodguy', null))
        ->toThrow(PostUnavailable::class);
    expect((new XAdapter)->requiresAuth())->toBeFalse();
});

it('yields null caption and handle when the oEmbed body has neither html nor author_url', function () {
    Http::fake(['publish.x.com/oembed*' => Http::response(['author_name' => 'Food Guy'])]);

    $data = (new XAdapter)->fetchMetadata('https://x.com/foodguy/status/9', null);

    expect($data->caption)->toBeNull()
        ->and($data->authorHandle)->toBeNull()
        ->and($data->authorDisplayName)->toBe('Food Guy');
});

it('maps a connection error to FetchFailed (advance the chain)', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect(fn () => (new XAdapter)->fetchMetadata('https://x.com/u/status/1', null))
        ->toThrow(FetchFailed::class);
});

it('never yields media directly — video comes from the yt-dlp step in the chain', function () {
    $media = (new XAdapter)->fetchMedia(new SourcePostData(Platform::X, '1', 'https://x.com/u/status/1'), null);
    expect($media)->toBeInstanceOf(MediaFetchResult::class)
        ->and($media->media)->toBe([]);
    // The X kill switch is enforced in AdapterRegistry, not supports() — see
    // AdapterRegistryTest ("skips the whole chain when a platform is disabled").
});
