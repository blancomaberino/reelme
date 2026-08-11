<?php

use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('sets, changes and clears the country', function () {
    $user = User::factory()->create(['country_code' => null]);
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['country_code' => 'UY'])
        ->assertOk()
        ->assertJsonPath('data.user.country_code', 'UY');

    $this->patchJson('/api/v1/me', ['country_code' => 'ES'])
        ->assertOk()
        ->assertJsonPath('data.user.country_code', 'ES');
    expect($user->fresh()->country_code)->toBe('ES');

    // Null is a real answer, not a missing one — the user can un-say it.
    $this->patchJson('/api/v1/me', ['country_code' => null])
        ->assertOk()
        ->assertJsonPath('data.user.country_code', null)
        ->assertJsonPath('data.user.country_name', null);
    expect($user->fresh()->country_code)->toBeNull();
});

it('normalizes lowercase input to the stored uppercase form', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['country_code' => 'uy'])
        ->assertOk()
        ->assertJsonPath('data.user.country_code', 'UY');

    // The column has to match `places.country_code` exactly or a person and a
    // place in the same country never compare equal.
    expect($user->fresh()->country_code)->toBe('UY');
});

it('rejects anything that is not a real ISO 3166-1 alpha-2 code', function () {
    $user = User::factory()->create(['country_code' => 'UY']);
    Sanctum::actingAs($user);

    // ZZ: ICU's unknown-region sentinel, which renders as a name.
    // USA: the alpha-3 code, the most likely honest mistake.
    // U1 / 12: shape-valid junk that a `max:2` rule would wave through.
    foreach (['ZZ', 'USA', 'u1', '12', 'España'] as $bogus) {
        $this->patchJson('/api/v1/me', ['country_code' => $bogus])
            ->assertStatus(422)
            ->assertJsonPath('error.details.country_code', fn ($v) => is_array($v));
    }

    // And none of them got through: the stored value is untouched.
    expect($user->fresh()->country_code)->toBe('UY');
});

it('returns the country on GET /me, named in the request locale', function () {
    Sanctum::actingAs(User::factory()->create(['country_code' => 'ES']));

    $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.user.country_code', 'ES')
        ->assertJsonPath('data.user.country_name', 'Spain');

    $this->getJson('/api/v1/me', ['Accept-Language' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.user.country_name', 'España');
});

it('exposes the country on a PUBLIC profile, both code and localized name', function () {
    User::factory()->create(['username' => 'viajera', 'is_public' => true, 'country_code' => 'ES']);

    $es = $this->getJson('/api/v1/users/viajera', ['Accept-Language' => 'es'])->assertOk();
    expect($es->json('data.profile.country_code'))->toBe('ES')
        ->and($es->json('data.profile.country_name'))->toBe('España');

    // Same row, same payload shape, different language — the name follows the
    // reader, the code never moves.
    $en = $this->getJson('/api/v1/users/viajera', ['Accept-Language' => 'en'])->assertOk();
    expect($en->json('data.profile.country_code'))->toBe('ES')
        ->and($en->json('data.profile.country_name'))->toBe('Spain');

    // Contract: both spellings validate against user-profile.json.
    expect(ApiSchema::errors(ApiSchema::validate($es->json('data.profile'), 'user-profile')))->toBe([])
        ->and(ApiSchema::errors(ApiSchema::validate($en->json('data.profile'), 'user-profile')))->toBe([]);
});

it('keeps the contract satisfied when no country is set', function () {
    User::factory()->create(['username' => 'nowhere', 'is_public' => true, 'country_code' => null]);

    $res = $this->getJson('/api/v1/users/nowhere')->assertOk();

    // Present-and-null, not absent: the schema requires the keys, and a client
    // reading `profile.country_code` must never hit `undefined`.
    expect($res->json('data.profile'))->toHaveKeys(['country_code', 'country_name'])
        ->and($res->json('data.profile.country_code'))->toBeNull()
        ->and($res->json('data.profile.country_name'))->toBeNull()
        ->and(ApiSchema::errors(ApiSchema::validate($res->json('data.profile'), 'user-profile')))->toBe([]);
});

it('does not leak the country from a private profile', function () {
    User::factory()->create(['username' => 'hermitcrab', 'is_public' => false, 'country_code' => 'ES']);

    // Guest and stranger both 404 — the country is inside a payload nobody else
    // can reach, which is the only place it is safe to be public.
    $this->getJson('/api/v1/users/hermitcrab')->assertStatus(404);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/users/hermitcrab')->assertStatus(404);
});
