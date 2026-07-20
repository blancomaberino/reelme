<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\ManualUploadAdapter;
use App\Adapters\OEmbedAdapter;
use App\Adapters\TikTokAdapter;
use App\Adapters\XAdapter;
use App\Adapters\YouTubeAdapter;
use App\Adapters\YtDlpAdapter;
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

dataset('platform lead adapters', [
    'x' => ['https://x.com/u/status/1', XAdapter::class],
    'tiktok' => ['https://www.tiktok.com/@u/video/1', TikTokAdapter::class],
    'youtube' => ['https://youtu.be/dQw4w9WgXcQ', YouTubeAdapter::class],
    'instagram' => ['https://www.instagram.com/reel/A/', OEmbedAdapter::class],
]);

it('leads each platform chain with its dedicated metadata adapter (T-014)', function (string $url, string $lead) {
    $chain = registry()->resolve($url);

    // Metadata adapter first, yt-dlp for media in the middle, manual last.
    expect($chain[0])->toBeInstanceOf($lead)
        ->and($chain)->toHaveCount(3)
        ->and($chain[1])->toBeInstanceOf(YtDlpAdapter::class)
        ->and(end($chain))->toBeInstanceOf(ManualUploadAdapter::class);
})->with('platform lead adapters');

it('skips the entire chain (manual-only) when a platform kill switch is off (T-014)', function () {
    // Disabled BEFORE the registry singleton is first resolved so it captures it.
    config()->set('ingestion.platforms.tiktok.enabled', false);

    $chain = registry()->resolve('https://www.tiktok.com/@u/video/1');

    // Neither the TikTok metadata adapter NOR yt-dlp runs — only manual remains.
    expect($chain)->toHaveCount(1)
        ->and($chain[0])->toBeInstanceOf(ManualUploadAdapter::class);
});

it('leaves an unconfigured platform (instagram) enabled by default', function () {
    // No ingestion.platforms.instagram entry exists — it must still resolve.
    $chain = registry()->resolve('https://www.instagram.com/reel/A/');

    expect($chain)->toHaveCount(3)
        ->and($chain[0])->toBeInstanceOf(OEmbedAdapter::class);
});
