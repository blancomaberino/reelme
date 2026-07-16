<?php

use App\Services\Media\Instagram\InstagramWebClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * InstagramWebClient is the shared authed transport (T-075) behind both the
 * carousel-image resolver (mediaInfo) and the venue-profile locator (profile).
 * Driven with Http::fake — no network, no real cookies — asserting the auth
 * header/cookie, the handle SSRF guard, and the never-throw / null-on-failure
 * contract callers depend on.
 */
function webCookie(string $body = "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\n"): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'wck_');
    file_put_contents($path, $body);

    return $path;
}

function webClient(?string $cookies = null, bool $enabled = true): InstagramWebClient
{
    return new InstagramWebClient(cookiesPath: $cookies ?? webCookie(), timeout: 5, enabled: $enabled);
}

it('is ready only with enabled + a readable cookie file', function () {
    expect(webClient()->ready())->toBeTrue()
        ->and((new InstagramWebClient(cookiesPath: null))->ready())->toBeFalse()
        ->and(webClient(enabled: false)->ready())->toBeFalse()
        ->and((new InstagramWebClient(cookiesPath: '/no/such/file'))->ready())->toBeFalse();
});

it('fetches a profile with the app-id header + session cookie and returns data.user', function () {
    Http::fake(['www.instagram.com/api/v1/users/web_profile_info*' => Http::response([
        'data' => ['user' => ['full_name' => 'La Gran Burger', 'biography' => '🥩 asado 📍Barros Blancos']],
    ], 200)]);

    $user = webClient()->profile('lagranburgerok');

    expect($user)->toBeArray()
        ->and($user['full_name'])->toBe('La Gran Burger');
    Http::assertSent(function (Request $req) {
        return str_contains($req->url(), 'web_profile_info')
            && str_contains($req->url(), 'username=lagranburgerok')
            && $req->hasHeader('x-ig-app-id', '936619743392459')
            && $req->header('Cookie')[0] === 'sessionid=SECRET';
    });
});

it('accepts a handle with a leading @ (normalized) and rejects an injection-y one without a request', function () {
    Http::fake(['*' => Http::response(['data' => ['user' => ['full_name' => 'X']]], 200)]);

    expect(webClient()->profile('@lagranburgerok'))->toBeArray(); // @ stripped

    // A handle with a query/path metacharacter never reaches the wire.
    expect(webClient()->profile('foo&bar=1'))->toBeNull();
    expect(webClient()->profile('a/../b'))->toBeNull();
    Http::assertSentCount(1); // only the first (valid) call went out
});

it('returns null when the profile payload has no user node', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    expect(webClient()->profile('somevenue'))->toBeNull();
});

it('builds the media info url from the pk and returns the decoded json', function () {
    Http::fake(['www.instagram.com/api/v1/media/*' => Http::response(['items' => [['id' => '1']]], 200)]);

    $json = webClient()->mediaInfo('3934170446803057467');

    expect($json['items'][0]['id'])->toBe('1');
    Http::assertSent(fn (Request $req) => str_contains($req->url(), '/media/3934170446803057467/info/'));
});

it('returns null (no request) when disabled or without a cookie', function () {
    Http::fake();

    expect(webClient(enabled: false)->profile('x'))->toBeNull()
        ->and((new InstagramWebClient(cookiesPath: null))->profile('x'))->toBeNull();
    Http::assertNothingSent();
});

it('returns null on a 4xx (cookie-refresh signal), never throwing', function () {
    Http::fake(['*' => Http::response('', 403)]);

    expect(webClient()->profile('x'))->toBeNull()
        ->and(webClient()->mediaInfo('123'))->toBeNull();
});

it('returns null when the request throws (transport error), never propagating', function () {
    Http::fake(fn () => throw new ConnectionException('boom'));

    expect(webClient()->profile('x'))->toBeNull();
});

it('does not follow an expired-cookie redirect to the login page', function () {
    // allow_redirects=false → a 302 stays a 302 (unsuccessful) rather than
    // resolving to a 200 login HTML page that would parse as garbage.
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://www.instagram.com/accounts/login/'])]);

    expect(webClient()->profile('x'))->toBeNull();
});
