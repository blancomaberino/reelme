<?php

use App\Models\Device;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('registers a device for the authenticated user', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $res = $this->postJson('/api/v1/devices', [
        'token' => 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]',
        'platform' => 'ios',
        'device_name' => 'iPhone 15',
        'app_version' => '1.2.3',
    ]);

    $res->assertCreated()->assertJsonPath('data.platform', 'ios');

    $this->assertDatabaseHas('devices', [
        'expo_push_token' => 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]',
        'user_id' => $user->id,
        'platform' => 'ios',
        'device_name' => 'iPhone 15',
        'app_version' => '1.2.3',
    ]);
});

it('upserts on the token and refreshes metadata instead of duplicating', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'ios', 'app_version' => '1.0.0'])
        ->assertCreated();
    // Same token again with a new app version → same row, updated in place, 200.
    $this->postJson('/api/v1/devices', ['token' => 'tok-1', 'platform' => 'ios', 'app_version' => '2.0.0'])
        ->assertOk();

    expect(Device::where('expo_push_token', 'tok-1')->count())->toBe(1);
    expect(Device::where('expo_push_token', 'tok-1')->first()->app_version)->toBe('2.0.0');
});

it('reassigns a token to the current user on re-registration (per-install, not per-user)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    Device::factory()->for($a)->create(['expo_push_token' => 'shared-device']);

    Sanctum::actingAs($b);
    $this->postJson('/api/v1/devices', ['token' => 'shared-device', 'platform' => 'android'])->assertOk();

    // The one row now belongs to B — A must not receive B's pushes on this device.
    expect(Device::where('expo_push_token', 'shared-device')->count())->toBe(1);
    $this->assertDatabaseHas('devices', ['expo_push_token' => 'shared-device', 'user_id' => $b->id]);
});

it('rejects an invalid platform', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/devices', ['token' => 'tok', 'platform' => 'windows'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.platform', fn ($m) => $m !== null);
});

it('requires authentication to register', function () {
    $this->postJson('/api/v1/devices', ['token' => 'tok', 'platform' => 'ios'])->assertUnauthorized();
});

it('deletes a device by id for its owner', function () {
    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/devices/'.$device->id)->assertNoContent();
    $this->assertDatabaseMissing('devices', ['id' => $device->id]);
});

it('will not let a user delete another user\'s device by id', function () {
    $owner = User::factory()->create();
    $device = Device::factory()->for($owner)->create();

    Sanctum::actingAs(User::factory()->create());
    $this->deleteJson('/api/v1/devices/'.$device->id)->assertNotFound();
    $this->assertDatabaseHas('devices', ['id' => $device->id]);
});

it('deletes a device by raw token (logout convenience) scoped to the caller', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['expo_push_token' => 'logout-token']);
    // Another user's identically-improbable token must be untouched.
    $other = Device::factory()->create(['expo_push_token' => 'someone-else']);
    Sanctum::actingAs($user);

    $this->deleteJson('/api/v1/devices/logout-token')->assertNoContent();

    $this->assertDatabaseMissing('devices', ['expo_push_token' => 'logout-token']);
    $this->assertDatabaseHas('devices', ['id' => $other->id]);
});

it('is a no-op (204) when deleting a token the caller does not own', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Idempotent logout: an unknown token must not 404.
    $this->deleteJson('/api/v1/devices/never-registered')->assertNoContent();
});
