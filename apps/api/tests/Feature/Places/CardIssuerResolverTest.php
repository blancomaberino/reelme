<?php

use App\Services\Media\Instagram\InstagramWebClient;
use App\Services\Places\CardIssuerResolver;
use App\Services\Places\InstagramProfileLocator;
use Illuminate\Support\Facades\Http;

/*
| T-079 — resolving a bank/card issuer's display name from its Instagram @handle.
| CardIssuerResolver delegates to InstagramProfileLocator (the T-075 venue-profile
| locator), projecting its full_name, so these drive the real client→locator path.
*/

function issuerCookie(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'ick_');
    file_put_contents($path, "# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t2000000000\tsessionid\tSECRET\n");

    return $path;
}

function makeIssuerResolver(): CardIssuerResolver
{
    return new CardIssuerResolver(
        new InstagramProfileLocator(new InstagramWebClient(cookiesPath: issuerCookie(), timeout: 5)),
    );
}

function fakeIssuerProfile(array $user): void
{
    Http::fake(['www.instagram.com/api/v1/users/web_profile_info*' => Http::response(['data' => ['user' => $user]], 200)]);
}

it('resolves a handle to the profile full_name', function () {
    fakeIssuerProfile(['full_name' => 'Santander Uruguay']);

    expect(makeIssuerResolver()->resolve('@santander.uy'))->toBe('Santander Uruguay');
});

it('returns null when the profile has no usable name', function () {
    fakeIssuerProfile(['full_name' => '']);

    expect(makeIssuerResolver()->resolve('whoever'))->toBeNull();
});

it('never throws and returns null on a dead/blocked profile', function () {
    Http::fake(['www.instagram.com/api/v1/users/web_profile_info*' => Http::response('', 404)]);

    expect(makeIssuerResolver()->resolve('gone'))->toBeNull();
});

it('caches per handle — a repeated issuer fetches once', function () {
    fakeIssuerProfile(['full_name' => 'Itaú']);
    $resolver = makeIssuerResolver();

    $resolver->resolve('itau.uy');
    $resolver->resolve('@itau.uy'); // same handle, normalized

    Http::assertSentCount(1);
});

it('ignores a blank handle without a request', function () {
    Http::fake();

    expect(makeIssuerResolver()->resolve('   '))->toBeNull();
    Http::assertNothingSent();
});
