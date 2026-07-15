<?php

use App\Enums\Platform;
use App\Models\SourcePost;
use App\Services\Media\Images\InstagramApiResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * InstagramApiResolver reads every carousel slide from Instagram's web media API
 * (`/api/v1/media/{pk}/info/`). Drives it with Http::fake — no network, no real
 * cookies — asserting the pk math, cookie auth, slide parsing, and the
 * never-throw / fall-through contract the resolver chain depends on.
 */
function igPost(array $overrides = []): SourcePost
{
    return SourcePost::factory()->make(array_merge([
        'platform' => Platform::Instagram,
        'external_id' => 'DaY-y1fiTs7',
        'url' => 'https://www.instagram.com/p/DaY-y1fiTs7/',
        'influencer_id' => null,
    ], $overrides));
}

/** Write a Netscape cookies.txt to a temp path and return it. */
function cookieFile(string $body = "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\n"): string
{
    $path = tempnam(sys_get_temp_dir(), 'ck_');
    file_put_contents($path, $body);

    return $path;
}

function mediaInfo(array $items): array
{
    return ['items' => $items];
}

it('decodes the shortcode to the correct numeric media pk in the request url', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([]), 200)]);

    (new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost());

    // DaY-y1fiTs7 base64-decodes (IG alphabet) to this pk.
    Http::assertSent(fn ($req) => str_contains($req->url(), '/media/3934170446803057467/info/'));
});

it('returns one best (largest, https) image per carousel slide, in order', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([[
        'carousel_media' => [
            ['image_versions2' => ['candidates' => [
                ['url' => 'https://cdn.test/1-small.jpg', 'width' => 320, 'height' => 320],
                ['url' => 'https://cdn.test/1-big.jpg', 'width' => 1080, 'height' => 1080],
            ]]],
            ['image_versions2' => ['candidates' => [
                ['url' => 'https://cdn.test/2-big.jpg', 'width' => 1080, 'height' => 1080],
                ['url' => 'http://cdn.test/2-insecure.jpg', 'width' => 2000, 'height' => 2000],
            ]]],
        ],
    ]]), 200)]);

    $urls = (new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost());

    // Slide 1 = biggest candidate; slide 2 = biggest HTTPS one (the http:// is skipped).
    expect($urls)->toBe(['https://cdn.test/1-big.jpg', 'https://cdn.test/2-big.jpg']);
});

it('uses the cover frame (image_versions2) of a video slide', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([[
        'carousel_media' => [
            [
                'video_versions' => [['url' => 'https://cdn.test/clip.mp4']],
                'image_versions2' => ['candidates' => [['url' => 'https://cdn.test/cover.jpg', 'width' => 720, 'height' => 720]]],
            ],
        ],
    ]]), 200)]);

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))
        ->toBe(['https://cdn.test/cover.jpg']);
});

it('handles a single-image post (no carousel_media)', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([[
        'image_versions2' => ['candidates' => [['url' => 'https://cdn.test/only.jpg', 'width' => 1080, 'height' => 1080]]],
    ]]), 200)]);

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))
        ->toBe(['https://cdn.test/only.jpg']);
});

it('dedupes a slide URL repeated across the carousel', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([[
        'carousel_media' => [
            ['image_versions2' => ['candidates' => [['url' => 'https://cdn.test/dup.jpg', 'width' => 800, 'height' => 800]]]],
            ['image_versions2' => ['candidates' => [['url' => 'https://cdn.test/dup.jpg', 'width' => 800, 'height' => 800]]]],
        ],
    ]]), 200)]);

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))
        ->toBe(['https://cdn.test/dup.jpg']);
});

it('sends the session cookie and app-id header (parsing an #HttpOnly_ line)', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([]), 200)]);

    $path = cookieFile("# Netscape HTTP Cookie File\n#HttpOnly_.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET123\n");
    (new InstagramApiResolver(cookiesPath: $path))->resolve(igPost());

    Http::assertSent(function ($req) {
        return $req->hasHeader('x-ig-app-id', '936619743392459')
            && str_contains($req->header('Cookie')[0], 'sessionid=SECRET123');
    });
});

it('is a no-op (no HTTP call) when no cookie file is configured', function () {
    Http::fake();

    expect((new InstagramApiResolver(cookiesPath: null))->resolve(igPost()))->toBe([]);
    Http::assertNothingSent();
});

it('falls through (returns []) on a non-2xx response — the cookie-expired signal', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response('', 403)]);

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))->toBe([]);
});

it('never throws — a transport error returns []', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))->toBe([]);
});

it('skips non-Instagram posts', function () {
    Http::fake();

    $post = igPost(['platform' => Platform::Tiktok]);
    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve($post))->toBe([]);
    Http::assertNothingSent();
});

it('is a no-op when disabled', function () {
    Http::fake();

    expect((new InstagramApiResolver(cookiesPath: cookieFile(), enabled: false))->resolve(igPost()))->toBe([]);
    Http::assertNothingSent();
});

it('parses the shortcode from a /reel/ URL when external_id is not a shortcode', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([]), 200)]);

    $post = igPost(['external_id' => 'not a code', 'url' => 'https://www.instagram.com/reel/DaY-y1fiTs7/']);
    (new InstagramApiResolver(cookiesPath: cookieFile()))->resolve($post);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/media/3934170446803057467/info/'));
});

it('returns [] when there is no resolvable shortcode', function () {
    Http::fake();

    $post = igPost(['external_id' => '', 'url' => 'https://www.instagram.com/accounts/login/']);
    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve($post))->toBe([]);
    Http::assertNothingSent();
});

it('drops a slide whose only candidate is non-https', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([[
        'carousel_media' => [
            ['image_versions2' => ['candidates' => [['url' => 'http://cdn.test/insecure.jpg', 'width' => 1080, 'height' => 1080]]]],
            ['image_versions2' => ['candidates' => [['url' => 'https://cdn.test/ok.jpg', 'width' => 1080, 'height' => 1080]]]],
        ],
    ]]), 200)]);

    // The http-only slide contributes nothing; only the https slide survives.
    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))
        ->toBe(['https://cdn.test/ok.jpg']);
});

it('returns [] on a non-JSON (HTML) response body', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response('<!DOCTYPE html><html>login</html>', 200)]);

    expect((new InstagramApiResolver(cookiesPath: cookieFile()))->resolve(igPost()))->toBe([]);
});

it('strips a trailing CR from a CRLF-exported cookies.txt', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([]), 200)]);

    // Windows/extension exports are CRLF — the value must not keep the \r.
    $path = cookieFile("# Netscape HTTP Cookie File\r\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\r\n");
    (new InstagramApiResolver(cookiesPath: $path))->resolve(igPost());

    Http::assertSent(fn ($req) => $req->header('Cookie')[0] === 'sessionid=SECRET');
});

it('joins multiple cookies into one header', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(mediaInfo([]), 200)]);

    $body = "# Netscape HTTP Cookie File\n"
        ."broken-line-too-few-cols\n"
        .".instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tS1\n"
        .".instagram.com\tTRUE\t/\tTRUE\t2000000000\tds_user_id\t42\n";
    (new InstagramApiResolver(cookiesPath: cookieFile($body)))->resolve(igPost());

    Http::assertSent(fn ($req) => $req->header('Cookie')[0] === 'sessionid=S1; ds_user_id=42');
});
