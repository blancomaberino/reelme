<?php

use App\Enums\Platform;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Configure real (fake-valued) Instagram OAuth credentials for the flow. */
beforeEach(function () {
    config([
        'services.instagram.client_id' => 'test-client-id',
        'services.instagram.client_secret' => 'test-client-secret',
        'services.instagram.redirect' => 'https://api.reelmap.test/api/v1/platform-accounts/instagram/callback',
        'services.instagram.scopes' => ['instagram_business_basic'],
    ]);
});

/** A mapped Socialite user the callback would receive after code exchange. */
function fakeSocialiteUser(string $id = '17841400000000009', string $nickname = 'lagranburgerok'): SocialiteUser
{
    $user = (new SocialiteUser)->map(['id' => $id, 'nickname' => $nickname]);
    $user->setToken('graph_tok')->setRefreshToken('refresh_tok')->setExpiresIn(5_184_000);
    $user->approvedScopes = ['instagram_business_basic'];

    return $user;
}

/** Stub the Socialite `instagram` driver so the callback resolves $user (no network). */
function mockInstagramOAuth(SocialiteUser $user): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('instagram')->andReturn($provider);
}

it('lists the caller\'s linked accounts without ever leaking tokens', function () {
    $user = User::factory()->create();
    PlatformAccount::factory()->for($user)->create(['handle' => 'lagranburgerok', 'access_token' => 'secret']);
    // Another user's account must not appear.
    PlatformAccount::factory()->create();

    Sanctum::actingAs($user);
    $res = $this->getJson('/api/v1/platform-accounts');

    $res->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.handle', 'lagranburgerok')
        ->assertJsonPath('data.0.platform', 'instagram')
        ->assertJsonPath('data.0.status', 'active');

    expect($res->json('data.0'))->not->toHaveKey('access_token')
        ->and($res->json('data.0'))->not->toHaveKey('refresh_token');
});

it('starts the OAuth flow with an authorize URL carrying a single-use signed state', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $res = $this->postJson('/api/v1/platform-accounts/instagram/link');

    $res->assertOk();
    $url = (string) $res->json('data.authorize_url');
    expect($url)->toContain('client_id=test-client-id')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('api.reelmap.test');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['state'] ?? null)->toBeString()->not->toBeEmpty()
        ->and($query['redirect_uri'] ?? null)->toBe('https://api.reelmap.test/api/v1/platform-accounts/instagram/callback');
    // The nonce is cached bound to this user (consumed on callback).
    expect(Cache::get('platform_link:'.$query['state']))->toBe($user->id);
});

it('rejects linking a non-Instagram platform with a 422', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/platform-accounts/tiktok/link')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unsupported_platform');
});

it('returns 503 when Instagram linking is not configured', function () {
    config(['services.instagram.client_id' => null, 'services.instagram.client_secret' => null]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/platform-accounts/instagram/link')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'link_unavailable');
});

it('links the account on a valid state-signed callback', function () {
    $user = User::factory()->create();
    $state = 'nonce-abc';
    Cache::put('platform_link:'.$state, $user->id, 600);

    mockInstagramOAuth(fakeSocialiteUser());

    $res = $this->get('/api/v1/platform-accounts/instagram/callback?state='.$state.'&code=oauth-code');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('reelmap://platform-linked')
        ->and($res->headers->get('Location'))->toContain('status=ok');

    $account = PlatformAccount::where('user_id', $user->id)->where('platform', Platform::Instagram)->sole();
    expect($account->external_user_id)->toBe('17841400000000009')
        ->and($account->handle)->toBe('lagranburgerok')
        ->and($account->access_token)->toBe('graph_tok')          // decrypted accessor
        ->and($account->refresh_token)->toBe('refresh_tok')
        ->and($account->scopes)->toBe(['instagram_business_basic'])
        ->and($account->token_expires_at)->not->toBeNull();

    // The state nonce is single-use — consumed after the callback.
    expect(Cache::get('platform_link:'.$state))->toBeNull();
});

it('updates (never duplicates) the account when re-linking', function () {
    $user = User::factory()->create();
    $existing = PlatformAccount::factory()->for($user)->create([
        'external_user_id' => '17841400000000009',
        'access_token' => 'old_tok',
    ]);

    $state = 'nonce-relink';
    Cache::put('platform_link:'.$state, $user->id, 600);
    mockInstagramOAuth(fakeSocialiteUser());

    $this->get('/api/v1/platform-accounts/instagram/callback?state='.$state.'&code=c')->assertRedirect();

    expect(PlatformAccount::where('user_id', $user->id)->count())->toBe(1)
        ->and($existing->fresh()->access_token)->toBe('graph_tok');
});

it('rejects a callback with a missing/expired state (login-CSRF guard)', function () {
    Socialite::shouldReceive('driver->stateless->user')->never();

    $res = $this->get('/api/v1/platform-accounts/instagram/callback?state=unknown&code=c');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('status=invalid_state');
    expect(PlatformAccount::count())->toBe(0);
});

it('refuses to link an Instagram identity already owned by another user', function () {
    $owner = User::factory()->create();
    PlatformAccount::factory()->for($owner)->create(['external_user_id' => '17841400000000009']);

    $intruder = User::factory()->create();
    $state = 'nonce-conflict';
    Cache::put('platform_link:'.$state, $intruder->id, 600);
    mockInstagramOAuth(fakeSocialiteUser('17841400000000009'));

    $res = $this->get('/api/v1/platform-accounts/instagram/callback?state='.$state.'&code=c');

    $res->assertRedirect();
    expect($res->headers->get('Location'))->toContain('status=conflict');
    // No second row created; the intruder gains nothing.
    expect(PlatformAccount::where('user_id', $intruder->id)->count())->toBe(0)
        ->and(PlatformAccount::where('external_user_id', '17841400000000009')->count())->toBe(1);
});

it('unlinks the caller\'s own account', function () {
    $user = User::factory()->create();
    $account = PlatformAccount::factory()->for($user)->create();

    Sanctum::actingAs($user);
    $this->deleteJson('/api/v1/platform-accounts/'.$account->id)->assertOk();

    expect(PlatformAccount::find($account->id))->toBeNull();
});

it('forbids unlinking someone else\'s account (403, row untouched)', function () {
    $account = PlatformAccount::factory()->create();

    Sanctum::actingAs(User::factory()->create());
    $this->deleteJson('/api/v1/platform-accounts/'.$account->id)->assertForbidden();

    expect(PlatformAccount::find($account->id))->not->toBeNull();
});

it('requires authentication for the linked-accounts endpoints', function () {
    $this->getJson('/api/v1/platform-accounts')->assertUnauthorized();
    $this->postJson('/api/v1/platform-accounts/instagram/link')->assertUnauthorized();
});
