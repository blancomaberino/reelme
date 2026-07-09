<?php

use App\Models\User;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Maya Diner',
        'username' => 'maya',
        'email' => 'maya@example.com',
        'password' => 'secret123!',
        'device_name' => 'cli',
    ], $overrides);
}

it('registers a user and issues a working token', function () {
    $response = $this->postJson('/api/v1/auth/register', registerPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.username', 'maya')
        ->assertJsonPath('data.user.id', fn ($id) => is_string($id))
        // DB-side defaults must be reflected on the register response, not null.
        ->assertJsonPath('data.user.is_public', true)
        ->assertJsonPath('data.user.is_influencer', false)
        ->assertJsonPath('data.user.is_admin', false)
        ->assertJsonMissingPath('data.user.password');

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'maya@example.com');
});

it('rejects duplicate email with a validation envelope', function () {
    User::factory()->create(['email' => 'maya@example.com']);

    $this->postJson('/api/v1/auth/register', registerPayload(['username' => 'other']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.email', fn ($v) => is_array($v));
});

it('treats usernames case-insensitively (citext)', function () {
    User::factory()->create(['username' => 'Maya']);

    $this->postJson('/api/v1/auth/register', registerPayload(['username' => 'maya', 'email' => 'new@example.com']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a weak password', function () {
    $this->postJson('/api/v1/auth/register', registerPayload(['password' => '123']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('logs in with valid credentials', function () {
    User::factory()->create([
        'email' => 'maya@example.com',
        'password' => 'secret123!',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'MAYA@example.com', // citext: case-insensitive match
        'password' => 'secret123!',
        'device_name' => 'iphone',
    ])->assertOk()->assertJsonPath('data.token', fn ($t) => is_string($t));
});

it('rejects login with the wrong password', function () {
    User::factory()->create(['email' => 'maya@example.com', 'password' => 'secret123!']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'maya@example.com',
        'password' => 'wrong-password',
        'device_name' => 'iphone',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('rejects login for a soft-deleted user', function () {
    $user = User::factory()->create(['email' => 'gone@example.com', 'password' => 'secret123!']);
    $user->delete();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'gone@example.com',
        'password' => 'secret123!',
        'device_name' => 'iphone',
    ])->assertStatus(422);
});

it('issues one token per device name on login', function () {
    $user = User::factory()->create(['email' => 'maya@example.com', 'password' => 'secret123!']);

    $login = fn () => $this->postJson('/api/v1/auth/login', [
        'email' => 'maya@example.com', 'password' => 'secret123!', 'device_name' => 'iphone',
    ])->json('data.token');

    $login();
    $login();

    expect($user->fresh()->tokens()->where('name', 'iphone')->count())->toBe(1);
});

it('revokes the current token on logout', function () {
    $token = $this->postJson('/api/v1/auth/register', registerPayload())->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertOk()->assertJsonPath('data.ok', true);

    // Flush the cached guard so the next call re-resolves auth like a fresh
    // request would in production (the token row is already deleted).
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('rotates the token on refresh', function () {
    $old = $this->postJson('/api/v1/auth/register', registerPayload())->json('data.token');

    $new = $this->withToken($old)->postJson('/api/v1/auth/refresh')
        ->assertOk()->json('data.token');

    expect($new)->not->toBe($old);

    $this->app['auth']->forgetGuards();

    $this->withToken($old)->getJson('/api/v1/me')->assertStatus(401);
    $this->withToken($new)->getJson('/api/v1/me')->assertOk();
});

it('returns 401 envelope for unauthenticated /me', function () {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('exposes the social sign-in route as a 501 stub', function () {
    $this->postJson('/api/v1/auth/social', ['provider' => 'apple', 'id_token' => 'x'])
        ->assertStatus(501)
        ->assertJsonPath('error.code', 'not_implemented');
});

it('throttles auth endpoints at 5 requests per minute per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => "nobody{$i}@example.com", 'password' => 'x', 'device_name' => 'cli',
        ]);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com', 'password' => 'x', 'device_name' => 'cli',
    ])->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});
