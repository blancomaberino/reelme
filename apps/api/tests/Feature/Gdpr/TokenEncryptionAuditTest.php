<?php

use App\Http\Resources\UserResource;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Encrypted-at-rest audit for platform OAuth tokens (T-050 step 6, NFR-9).
 *
 * A cast is one line, and losing it is one line — someone refactors `casts()`,
 * every test still passes (the model reads back exactly what it wrote), and the
 * only difference is that the database now holds live Instagram credentials in
 * plaintext. Nothing in the app would notice. That is precisely why this test
 * reads the COLUMN rather than the attribute.
 */
it('stores platform tokens as ciphertext, never as the value we were given', function () {
    $account = PlatformAccount::factory()->create([
        'access_token' => 'tok_plaintext_canary',
        'refresh_token' => 'refresh_plaintext_canary',
    ]);

    $raw = DB::table('platform_accounts')->where('id', $account->id)->first();

    expect($raw->access_token)->not->toContain('tok_plaintext_canary')
        ->and($raw->refresh_token)->not->toContain('refresh_plaintext_canary')
        // And it is genuinely reversible for the app — an "encrypted" column
        // that cannot be read back would fail the private-fetch path instead.
        ->and($account->fresh()->access_token)->toBe('tok_plaintext_canary');
});

it('declares the encrypted casts explicitly', function () {
    // The behaviour above is the real assertion; this pins the mechanism, so a
    // change that swaps encryption for something weaker fails HERE with an
    // obvious diff rather than in a string-matching test.
    $casts = (new PlatformAccount)->getCasts();

    expect($casts['access_token'])->toBe('encrypted')
        ->and($casts['refresh_token'])->toBe('encrypted');
});

it('never serialises a token through an API resource', function () {
    $user = User::factory()->create();
    PlatformAccount::factory()->for($user)->create(['access_token' => 'tok_plaintext_canary']);

    // The account list endpoint is the one place these rows reach the wire.
    $response = $this->actingAs($user)->getJson('/api/v1/platform-accounts');
    $response->assertOk();

    expect($response->getContent())->not->toContain('tok_plaintext_canary')
        ->not->toContain('access_token')
        ->not->toContain('refresh_token');

    // And the user resource beside it must not leak the account's secrets
    // either — a nested relation is the usual way one escapes.
    $serialised = (string) json_encode((new UserResource($user->fresh()))->resolve());
    expect($serialised)->not->toContain('tok_plaintext_canary');
});

it('keeps the password hash and 2FA secret out of the user resource', function () {
    $user = User::factory()->create();

    $serialised = (string) json_encode((new UserResource($user->fresh()))->resolve());

    expect($serialised)->not->toContain('password')
        ->not->toContain('two_factor_secret')
        ->not->toContain('remember_token');
});
