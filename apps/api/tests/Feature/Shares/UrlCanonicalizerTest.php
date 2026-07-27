<?php

use App\Services\Ingestion\UrlCanonicalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('refuses to follow a shortlink redirect into an internal host (SSRF)', function () {
    Http::fake([
        'https://t.co/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://t.co/abc');

    // The redirect target (link-local metadata IP) is rejected, so expansion
    // stops at the original shortlink — the request to the internal host is never
    // made and the returned URL is exactly the input (not the vetted target).
    expect($result->url)->toBe('https://t.co/abc');
});

it('refuses a redirect to loopback', function () {
    Http::fake([
        'https://vm.tiktok.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:6379/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://vm.tiktok.com/xyz');

    // Refused → expansion halts at the shortlink, never the loopback target.
    expect($result->url)->toBe('https://vm.tiktok.com/xyz');
});

it('refuses a redirect to an IPv6 loopback', function () {
    // Literal IPv6 target (no DNS → network-free). The bracket is stripped so ::1
    // validates as the reserved loopback it is, and the redirect is refused.
    Http::fake([
        'https://t.co/*' => Http::response('', 301, ['Location' => 'http://[::1]:6379/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://t.co/abc');

    // Refused → expansion stops at the original shortlink, never the ::1 target.
    expect($result->url)->toBe('https://t.co/abc');
});

it('expands a shortlink into a public IPv6 target and pins it', function () {
    // A public IPv6 literal (Cloudflare DNS) is validated and followed. No DNS
    // lookup happens (literal IP) and the HTTP client is faked, so this is
    // network-free while still exercising the bracketed --resolve pin path.
    Http::fake([
        'https://vt.tiktok.com/*' => Http::response('', 301, ['Location' => 'http://[2606:4700:4700::1111]/video/42']),
        '*' => Http::response('', 200),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://vt.tiktok.com/xyz');

    expect($result->url)->toBe('http://[2606:4700:4700::1111]/video/42');
});

it('strips tracking params and extracts the platform post id (no network)', function () {
    $result = app(UrlCanonicalizer::class)
        ->canonicalize('https://www.instagram.com/reel/ABC123/?igsh=xyz&utm_source=ig');

    expect($result->platform?->value)->toBe('instagram')
        ->and($result->externalId)->toBe('ABC123')
        ->and($result->url)->not->toContain('igsh')
        ->and($result->url)->not->toContain('utm_source');
});

it('extracts a tiktok video id', function () {
    $result = app(UrlCanonicalizer::class)->canonicalize('https://www.tiktok.com/@user/video/7300000000000000000');

    expect($result->platform?->value)->toBe('tiktok')
        ->and($result->externalId)->toBe('7300000000000000000');
});

it('expands a shortlink into a public target and pins it', function () {
    // vt.tiktok.com legitimately 301s to a public IPv4 literal (no DNS lookup, so
    // network-free), which is validated + pinned and followed to its 200 end.
    Http::fake([
        'https://vt.tiktok.com/*' => Http::response('', 301, ['Location' => 'http://93.184.216.34/video/123']),
        'http://93.184.216.34/*' => Http::response('', 200),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://vt.tiktok.com/xyz');

    expect($result->url)->toBe('http://93.184.216.34/video/123');
});

it('resolves a relative Location against the current host while expanding', function () {
    // hop 1: shortlink → absolute public-IP target; hop 2: that target → a
    // RELATIVE Location, which must resolve against the IP host (not the
    // shortlink). Using an IP literal keeps the second hop DNS-free.
    Http::fake([
        'https://vt.tiktok.com/*' => Http::response('', 301, ['Location' => 'http://93.184.216.34/first']),
        'http://93.184.216.34/first' => Http::response('', 302, ['Location' => '/second']),
        'http://93.184.216.34/second' => Http::response('', 200),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://vt.tiktok.com/xyz');

    expect($result->url)->toBe('http://93.184.216.34/second');
});

it('returns the original shortlink when expansion cannot connect', function () {
    // A connection error mid-expansion must not blow up canonicalization — it
    // falls back to the untouched shortlink so the caller can still try to fetch.
    Http::fake([
        'https://youtu.be/*' => fn () => throw new ConnectionException('network down'),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://youtu.be/abcDEF123');

    expect($result->url)->toBe('https://youtu.be/abcDEF123');
});

it('expands a shortlink that resolves in place and extracts the post id', function () {
    // youtu.be is both a shortlink host and a YouTube host: it 200s in place
    // (no redirect), and the canonical URL yields the right YouTube video id.
    Http::fake(['https://youtu.be/*' => Http::response('', 200)]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://youtu.be/dQw4w9WgXcQ?si=track');

    expect($result->platform?->value)->toBe('youtube')
        ->and($result->externalId)->toBe('dQw4w9WgXcQ')
        ->and($result->url)->not->toContain('si=');
});
