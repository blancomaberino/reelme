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
    // Literal IPv6 target (no DNS → network-free). The pin path must treat the
    // reserved ::1 address as private and refuse to expand into it.
    Http::fake([
        'https://t.co/*' => Http::response('', 301, ['Location' => 'http://[::1]:6379/']),
    ]);

    $result = app(UrlCanonicalizer::class)->canonicalize('https://t.co/abc');

    expect($result->url)->not->toContain('::1');
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
