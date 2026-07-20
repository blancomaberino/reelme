<?php

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\InstagramGraphAdapter;
use App\Enums\MediaKind;
use App\Enums\Platform;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A linked Instagram account with a usable token. */
function igAccount(string $token = 'tok_123', string $handle = 'lagranburgerok'): LinkedAccount
{
    return new LinkedAccount(Platform::Instagram, '17841400000000001', $handle, $token);
}

/** A one-item /me/media page whose permalink matches PRIV123. */
function fakeGraphMedia(): void
{
    Http::fake(['*graph.instagram.com/me/media*' => Http::response(['data' => [
        [
            'id' => '17900000000000001',
            'caption' => 'Menú secreto 🍔 solo para seguidores',
            'media_type' => 'VIDEO',
            'media_url' => 'https://cdn.example.test/priv123.mp4',
            'permalink' => 'https://www.instagram.com/reel/PRIV123/',
            'timestamp' => '2026-05-01T12:00:00+0000',
        ],
    ]])]);
}

it('supports instagram URLs and always requires auth', function () {
    $adapter = new InstagramGraphAdapter;

    expect($adapter->supports('https://www.instagram.com/reel/PRIV123/'))->toBeTrue()
        ->and($adapter->supports('https://youtu.be/x'))->toBeFalse()
        ->and($adapter->supports('https://instagram.com.attacker.test/reel/x/'))->toBeFalse()
        ->and($adapter->requiresAuth())->toBeTrue();
});

it('fetches a private post caption + media with the linked token', function () {
    fakeGraphMedia();

    $data = (new InstagramGraphAdapter)->fetchMetadata(
        'https://www.instagram.com/reel/PRIV123/',
        igAccount(),
    );

    expect($data->platform)->toBe(Platform::Instagram)
        ->and($data->externalId)->toBe('PRIV123')
        ->and($data->caption)->toContain('Menú secreto')
        ->and($data->authorHandle)->toBe('lagranburgerok')
        ->and($data->postedAt?->toDateString())->toBe('2026-05-01')
        ->and($data->media)->toHaveCount(1)
        ->and($data->media[0]->type)->toBe('video')
        ->and($data->raw['source'])->toBe('instagram_graph');

    // The token is a query VALUE against the fixed Graph host (SSRF boundary),
    // and the requested fields include caption + media_url.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.instagram.com/me/media')
        && str_contains($r->url(), 'access_token=tok_123')
        && str_contains(urldecode($r->url()), 'fields=id,caption,media_type,media_url,permalink,timestamp'));
});

it('resolves the downloadable video via fetchMedia with the linked token', function () {
    fakeGraphMedia();

    $result = (new InstagramGraphAdapter)->fetchMedia(
        new SourcePostData(Platform::Instagram, 'PRIV123', 'https://www.instagram.com/reel/PRIV123/'),
        igAccount(),
    );

    expect($result->media)->toHaveCount(1)
        ->and($result->media[0]->kind)->toBe(MediaKind::Video)
        ->and($result->media[0]->url)->toBe('https://cdn.example.test/priv123.mp4');
});

it('maps a missing token to fetch_auth_required and never calls the Graph API', function () {
    Http::fake();

    $call = fn () => (new InstagramGraphAdapter)->fetchMetadata('https://www.instagram.com/reel/PRIV123/', null);

    expect($call)->toThrow(PostUnavailable::class);
    try {
        $call();
    } catch (PostUnavailable $e) {
        expect($e->failureCode())->toBe('fetch_auth_required');
    }

    Http::assertNothingSent();
});

it('treats a wrong-platform account as no token (fetch_auth_required)', function () {
    Http::fake();
    $xAccount = new LinkedAccount(Platform::X, '42', 'someone', 'tok_x');

    try {
        (new InstagramGraphAdapter)->fetchMetadata('https://www.instagram.com/reel/PRIV123/', $xAccount);
        expect(false)->toBeTrue('expected PostUnavailable');
    } catch (PostUnavailable $e) {
        expect($e->failureCode())->toBe('fetch_auth_required');
    }
    Http::assertNothingSent();
});

it('maps a rejected token (401) to fetch_auth_required', function () {
    Http::fake(['*graph.instagram.com/me/media*' => Http::response('', 401)]);

    try {
        (new InstagramGraphAdapter)->fetchMetadata('https://www.instagram.com/reel/PRIV123/', igAccount());
        expect(false)->toBeTrue('expected PostUnavailable');
    } catch (PostUnavailable $e) {
        expect($e->failureCode())->toBe('fetch_auth_required');
    }
});

it('advances (PostUnavailable, not auth) when the post is not in the linked media', function () {
    Http::fake(['*graph.instagram.com/me/media*' => Http::response(['data' => [
        ['id' => '1', 'permalink' => 'https://www.instagram.com/reel/OTHER99/', 'media_type' => 'IMAGE'],
    ]])]);

    try {
        (new InstagramGraphAdapter)->fetchMetadata('https://www.instagram.com/reel/PRIV123/', igAccount());
        expect(false)->toBeTrue('expected PostUnavailable');
    } catch (PostUnavailable $e) {
        expect($e->failureCode())->toBe('fetch_unavailable');
    }
});

it('releases on a 429 as a retryable FetchFailed with Retry-After', function () {
    Http::fake(['*graph.instagram.com/me/media*' => Http::response('', 429, ['Retry-After' => '45'])]);

    try {
        (new InstagramGraphAdapter)->fetchMetadata('https://www.instagram.com/reel/PRIV123/', igAccount());
        expect(false)->toBeTrue('expected FetchFailed');
    } catch (FetchFailed $e) {
        expect($e->retryAfter)->toBe(45);
    }
});
