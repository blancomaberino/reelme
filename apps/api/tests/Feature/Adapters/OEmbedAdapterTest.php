<?php

use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\OEmbedAdapter;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('supports youtube and tiktok URLs, not others', function () {
    $adapter = new OEmbedAdapter;

    expect($adapter->supports('https://www.youtube.com/watch?v=abc123'))->toBeTrue()
        ->and($adapter->supports('https://youtu.be/abc123'))->toBeTrue()
        ->and($adapter->supports('https://www.tiktok.com/@chef/video/7123456789'))->toBeTrue()
        ->and($adapter->supports('https://www.instagram.com/reel/XYZ/'))->toBeFalse()
        ->and($adapter->supports('https://evil.youtube.com.attacker.test/x'))->toBeFalse();
});

it('maps a YouTube oEmbed response to caption + author', function () {
    Http::fake(['*/oembed*' => Http::response([
        'title' => 'BEST TACOS IN AUSTIN — La Barbecue review',
        'author_name' => 'Taco Hunter',
        'author_url' => 'https://www.youtube.com/@tacohunter',
        'type' => 'video',
    ])]);

    $data = (new OEmbedAdapter)->fetchMetadata('https://www.youtube.com/watch?v=dQw4w9WgXcQ', null);

    expect($data->platform)->toBe(Platform::Youtube)
        ->and($data->caption)->toBe('BEST TACOS IN AUSTIN — La Barbecue review')
        ->and($data->authorHandle)->toBe('tacohunter')
        ->and($data->authorDisplayName)->toBe('Taco Hunter')
        ->and($data->externalId)->toBe('dQw4w9WgXcQ')
        ->and($data->raw['source'])->toBe('oembed');

    // The request hits the hardcoded provider endpoint with the URL as a param
    // (not as the request host) — the SSRF-relevant assertion.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.youtube.com/oembed')
        && str_contains(urldecode($r->url()), 'url=https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
});

it('parks (PostUnavailable) on 401/403 and on a titleless body; media is empty', function () {
    Http::fake(['*/oembed*' => Http::response('', 401)]);
    expect(fn () => (new OEmbedAdapter)->fetchMetadata('https://youtu.be/private', null))
        ->toThrow(PostUnavailable::class);

    Http::fake(['*/oembed*' => Http::response(['author_name' => 'No Title'])]); // no title key
    expect(fn () => (new OEmbedAdapter)->fetchMetadata('https://youtu.be/blank', null))
        ->toThrow(PostUnavailable::class);

    expect((new OEmbedAdapter)->fetchMedia(new SourcePostData(Platform::Youtube, 'x', 'https://youtu.be/x'), null))
        ->toBeInstanceOf(MediaFetchResult::class)
        ->and((new OEmbedAdapter)->fetchMedia(new SourcePostData(Platform::Youtube, 'x', 'https://youtu.be/x'), null)->media)->toBe([]);
});

it('releases with backoff on a 429 rate-limit', function () {
    Http::fake(['*/oembed*' => Http::response('', 429, ['Retry-After' => '30'])]);

    try {
        (new OEmbedAdapter)->fetchMetadata('https://youtu.be/busy', null);
        $this->fail('Expected FetchFailed.');
    } catch (FetchFailed $e) {
        expect($e->retryAfter)->toBe(30);
    }
});

it('extracts the tiktok video id and handle', function () {
    Http::fake(['*/oembed*' => Http::response([
        'title' => 'Hidden ramen spot in Osaka 🍜',
        'author_name' => 'Osaka Eats',
        'author_url' => 'https://www.tiktok.com/@osaka.eats',
    ])]);

    $data = (new OEmbedAdapter)->fetchMetadata('https://www.tiktok.com/@osaka.eats/video/7123456789012', null);

    expect($data->platform)->toBe(Platform::Tiktok)
        ->and($data->externalId)->toBe('7123456789012')
        ->and($data->authorHandle)->toBe('osaka.eats');
});

it('raises PostUnavailable on a 404', function () {
    Http::fake(['*/oembed*' => Http::response('', 404)]);
    expect(fn () => (new OEmbedAdapter)->fetchMetadata('https://youtu.be/gone', null))
        ->toThrow(PostUnavailable::class);
});

it('raises FetchFailed on a 5xx', function () {
    Http::fake(['*/oembed*' => Http::response('', 500)]);
    expect(fn () => (new OEmbedAdapter)->fetchMetadata('https://youtu.be/oops', null))
        ->toThrow(FetchFailed::class);
});

it('raises FetchFailed without leaking the URL on a connection error', function () {
    Http::fake(fn () => throw new ConnectionException('dns boom'));

    try {
        (new OEmbedAdapter)->fetchMetadata('https://youtu.be/secret', null);
        $this->fail('Expected FetchFailed.');
    } catch (FetchFailed $e) {
        expect($e->getMessage())->not->toContain('secret');
    }
});
