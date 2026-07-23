<?php

use App\Services\Ingestion\UrlCanonicalizer;
use Illuminate\Support\Facades\Http;

it('refuses to follow a shortlink redirect into an internal host (SSRF)', function () {
    Http::fake([
        'https://t.co/*' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://t.co/abc');

    // The redirect target (link-local metadata IP) is rejected, so we never
    // expand into it — the request to the internal host is never made.
    expect($result->url)->not->toContain('169.254.169.254');
});

it('refuses a redirect to loopback', function () {
    Http::fake([
        'https://vm.tiktok.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1:6379/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://vm.tiktok.com/xyz');

    expect($result->url)->not->toContain('127.0.0.1');
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
