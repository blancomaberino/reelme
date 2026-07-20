<?php

use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\InstagramAdapter;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('supports instagram post/reel URLs only, rejecting other platforms and look-alikes', function () {
    $adapter = new InstagramAdapter;

    expect($adapter->supports('https://www.instagram.com/p/DaJVG6IPcR5/'))->toBeTrue()
        ->and($adapter->supports('https://instagram.com/reel/XYZ/'))->toBeTrue()
        // X/TikTok/YouTube have dedicated adapters now — not this one.
        ->and($adapter->supports('https://www.youtube.com/watch?v=abc123'))->toBeFalse()
        ->and($adapter->supports('https://www.tiktok.com/@chef/video/7123456789'))->toBeFalse()
        // Look-alike host must never be classified as Instagram.
        ->and($adapter->supports('https://instagram.com.attacker.test/p/x/'))->toBeFalse();
});

it('fetches an instagram post caption via the keyless oembed endpoint', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response([
        'title' => 'Nuevo cafecito en Cordón — Clara Café ☕️',
        'author_name' => 'somoscomiendo',
        'author_url' => 'https://www.instagram.com/somoscomiendo',
    ])]);

    $data = (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/DaJVG6IPcR5/', null);

    expect($data->platform)->toBe(Platform::Instagram)
        ->and($data->caption)->toContain('Clara Café')
        ->and($data->authorHandle)->toBe('somoscomiendo')
        ->and($data->externalId)->toBe('DaJVG6IPcR5')
        ->and($data->raw['source'])->toBe('oembed');

    // The URL is a query param on the hardcoded endpoint (SSRF boundary), UA sent.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.instagram.com/api/v1/oembed/')
        && str_contains(urldecode($r->url()), 'url=https://www.instagram.com/p/DaJVG6IPcR5/')
        && $r->hasHeader('User-Agent'));
});

it('rejects a non-instagram URL called directly and never requires auth', function () {
    // supports() gates this in the chain, but the adapter must still self-guard.
    expect(fn () => (new InstagramAdapter)->fetchMetadata('https://youtu.be/x', null))
        ->toThrow(PostUnavailable::class);
    expect((new InstagramAdapter)->requiresAuth())->toBeFalse();
});

it('parks (PostUnavailable) on a 401 and yields no media', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 401)]);
    expect(fn () => (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/private/', null))
        ->toThrow(PostUnavailable::class);

    $media = (new InstagramAdapter)->fetchMedia(
        new SourcePostData(Platform::Instagram, 'x', 'https://www.instagram.com/p/x/'),
        null,
    );
    expect($media)->toBeInstanceOf(MediaFetchResult::class)
        ->and($media->media)->toBe([]);
});

it('parks (PostUnavailable) on a 200 body with no title', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response(['author_name' => 'No Title'])]);
    expect(fn () => (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/blank/', null))
        ->toThrow(PostUnavailable::class);
});

it('hashes the external id when the URL has no shortcode and reads an @handle from author_url', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response([
        'title' => 'A saved collection',
        'author_url' => 'https://www.instagram.com/@some.handle',
    ])]);

    // No /p//reel//tv/ shortcode in the path → stable URL hash, not a crash.
    $url = 'https://www.instagram.com/explore/tags/food/';
    $data = (new InstagramAdapter)->fetchMetadata($url, null);

    expect($data->externalId)->toBe(substr(sha1($url), 0, 24))
        ->and($data->authorHandle)->toBe('some.handle');
});

it('releases with backoff on a 429 rate-limit', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 429, ['Retry-After' => '30'])]);

    try {
        (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/busy/', null);
        $this->fail('Expected FetchFailed.');
    } catch (FetchFailed $e) {
        expect($e->retryAfter)->toBe(30);
    }
});

it('raises PostUnavailable on a 404', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 404)]);
    expect(fn () => (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/gone/', null))
        ->toThrow(PostUnavailable::class);
});

it('raises FetchFailed on a 5xx', function () {
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 500)]);
    expect(fn () => (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/oops/', null))
        ->toThrow(FetchFailed::class);
});

it('raises FetchFailed without leaking the URL on a connection error', function () {
    Http::fake(fn () => throw new ConnectionException('dns boom'));

    try {
        (new InstagramAdapter)->fetchMetadata('https://www.instagram.com/p/secret-shortcode/', null);
        $this->fail('Expected FetchFailed.');
    } catch (FetchFailed $e) {
        expect($e->getMessage())->not->toContain('secret-shortcode');
    }
});
