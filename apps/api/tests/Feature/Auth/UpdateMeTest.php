<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('updates the authed user profile and derives age from birthdate', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    Sanctum::actingAs($user);

    $res = $this->patchJson('/api/v1/me', [
        'name' => 'Marcelo',
        'birthdate' => '1990-05-20',
        'favorite_topics' => ['ramen', 'coffee', '  '], // blank dropped
        'favorite_foods' => ['sushi', 'tacos'],
        'bio' => 'I map where the internet eats.',
    ])->assertOk();

    $res->assertJsonPath('data.user.name', 'Marcelo')
        ->assertJsonPath('data.user.birthdate', '1990-05-20')
        ->assertJsonPath('data.user.bio', 'I map where the internet eats.')
        ->assertJsonPath('data.user.favorite_topics', ['ramen', 'coffee'])
        ->assertJsonPath('data.user.favorite_foods', ['sushi', 'tacos']);

    // Age is derived (>= 30 for a 1990 DOB) — never stored.
    expect($res->json('data.user.age'))->toBeGreaterThanOrEqual(30);

    $user->refresh();
    expect($user->name)->toBe('Marcelo')
        ->and($user->birthdate->toDateString())->toBe('1990-05-20')
        ->and($user->favorite_topics)->toBe(['ramen', 'coffee']);
});

it('applies a partial update, leaving absent fields untouched', function () {
    $user = User::factory()->create(['name' => 'Keep Me', 'bio' => 'original bio']);
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['favorite_foods' => ['pizza']])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Keep Me')
        ->assertJsonPath('data.user.bio', 'original bio')
        ->assertJsonPath('data.user.favorite_foods', ['pizza']);
});

it('rejects an invalid birthdate and a duplicate username', function () {
    $taken = User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'marce_ok']);
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/me', ['birthdate' => '2999-01-01'])
        ->assertStatus(422)->assertJsonPath('error.details.birthdate', fn ($v) => is_array($v));

    $this->patchJson('/api/v1/me', ['username' => 'taken'])
        ->assertStatus(422)->assertJsonPath('error.details.username', fn ($v) => is_array($v));

    // Keeping your own username is fine (unique rule ignores your row).
    $this->patchJson('/api/v1/me', ['username' => $user->username])->assertOk();
});

it('requires authentication', function () {
    $this->patchJson('/api/v1/me', ['name' => 'x'])->assertStatus(401);
});
