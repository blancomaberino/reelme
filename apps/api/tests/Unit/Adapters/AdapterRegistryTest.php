<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\ManualUploadAdapter;
use App\Enums\Platform;
use Tests\TestCase;

uses(TestCase::class);

function registry(): AdapterRegistry
{
    return app(AdapterRegistry::class);
}

dataset('platform urls', [
    'instagram reel' => ['https://www.instagram.com/reel/DAbC123xyz/', Platform::Instagram],
    'instagram no-www' => ['https://instagram.com/p/ABC/', Platform::Instagram],
    'x status' => ['https://x.com/u/status/1', Platform::X],
    'twitter status' => ['https://twitter.com/u/status/1', Platform::X],
    'tiktok shortlink' => ['https://vm.tiktok.com/xyz/', Platform::Tiktok],
    'tiktok full' => ['https://www.tiktok.com/@u/video/1', Platform::Tiktok],
    'youtu.be' => ['https://youtu.be/abc', Platform::Youtube],
    'youtube watch' => ['https://www.youtube.com/watch?v=abc', Platform::Youtube],
]);

it('maps a URL to its platform', function (string $url, Platform $expected) {
    expect(registry()->platformFor($url))->toBe($expected);
})->with('platform urls');

it('returns null platform for an unknown host', function () {
    expect(registry()->platformFor('https://example.com/foo'))->toBeNull();
});

dataset('look-alike hosts', [
    'subdomain of attacker' => 'https://instagram.com.evil.com/reel/x',
    'prefix look-alike' => 'https://notinstagram.com/p/x',
    'tiktok look-alike' => 'https://tiktok.com.evil.com/v/1',
    'youtube look-alike' => 'https://youtube.com.evil.com/watch?v=x',
    'userinfo trick' => 'https://x.com.evil.com/status/1',
]);

it('never classifies a look-alike domain as a trusted platform', function (string $url) {
    // Suffix-anchored matching: an attacker host that merely contains the
    // platform domain as a substring must resolve to manual-only.
    expect(registry()->platformFor($url))->toBeNull();

    $chain = registry()->resolve($url);
    expect($chain)->toHaveCount(1)
        ->and($chain[0])->toBeInstanceOf(ManualUploadAdapter::class);
})->with('look-alike hosts');

it('resolves an unknown host to a manual-only chain', function () {
    $chain = registry()->resolve('https://nonsense.example/x');

    expect($chain)->toHaveCount(1)
        ->and($chain[0])->toBeInstanceOf(ManualUploadAdapter::class);
});

it('always terminates every chain in ManualUploadAdapter', function () {
    foreach (['https://www.instagram.com/reel/A/', 'https://x.com/u/status/1', 'https://example.com/z'] as $url) {
        $chain = registry()->resolve($url);
        expect(end($chain))->toBeInstanceOf(ManualUploadAdapter::class);
    }
});
