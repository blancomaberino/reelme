<?php

use App\Services\Media\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;

/**
 * The SSRF guard is disabled in the global test env (MEDIA_VERIFY_IMAGE_HOST=false),
 * so force it on here and drive it with IP literals — no DNS/network needed.
 */
beforeEach(function () {
    config(['media.verify_image_host' => true]);
});

it('rejects a non-https url', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('http://example.com/a.jpg'))
        ->toThrow(RuntimeException::class, 'https');
});

it('rejects a loopback ipv4 host', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https://127.0.0.1/a.jpg'))
        ->toThrow(RuntimeException::class, 'public');
});

it('rejects a private ipv4 host', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https://10.0.0.1/a.jpg'))
        ->toThrow(RuntimeException::class, 'public');
});

it('rejects the cloud metadata link-local address', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https://169.254.169.254/latest/meta-data/'))
        ->toThrow(RuntimeException::class, 'public');
});

it('rejects a loopback ipv6 literal', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https://[::1]/a.jpg'))
        ->toThrow(RuntimeException::class, 'public');
});

it('rejects a url with no host', function () {
    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https:///a.jpg'))
        ->toThrow(RuntimeException::class);
});

it('enforces the byte cap on an oversized body', function () {
    config(['media.verify_image_host' => false, 'media.max_image_download_bytes' => 8]);
    Http::fake([
        '*' => Http::response(str_repeat('x', 1000), 200),
    ]);

    expect(fn () => (new RemoteFileFetcher)->fetchToTemp('https://cdn.example.com/big.jpg'))
        ->toThrow(RuntimeException::class, 'size cap');
});

it('streams a within-cap body to a temp file', function () {
    config(['media.verify_image_host' => false, 'media.max_image_download_bytes' => 1024]);
    Http::fake([
        '*' => Http::response('small-image-bytes', 200),
    ]);

    $path = (new RemoteFileFetcher)->fetchToTemp('https://cdn.example.com/ok.jpg');

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('small-image-bytes');
    @unlink($path);
});
