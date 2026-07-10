<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

// --- Panel access control ---

it('redirects guests from the admin panel to login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('forbids non-admin users from the admin panel', function () {
    $this->actingAs(User::factory()->create()); // is_admin = false

    $this->get('/admin')->assertForbidden();
});

it('allows admins into the admin panel', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin')->assertOk();
});

it('blocks non-admins from the Users resource directly (not just /admin)', function () {
    $this->actingAs(User::factory()->create());

    // Hardens against panel-middleware regression: the resource page itself is denied.
    $this->get('/admin/users')->assertForbidden();
});

// --- Users resource ---

it('lists users and searches by username', function () {
    $this->actingAs(User::factory()->admin()->create());

    $target = User::factory()->create(['username' => 'findme']);
    $other = User::factory()->create(['username' => 'somebodyelse']);

    Livewire::test(ListUsers::class)
        ->searchTable('findme')
        ->assertCanSeeTableRecords([$target])
        ->assertCanNotSeeTableRecords([$other]);
});

it('edits role flags and persists them', function () {
    $this->actingAs(User::factory()->admin()->create());

    $user = User::factory()->create(['is_influencer' => false]);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['is_influencer' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->is_influencer)->toBeTrue();
});

// --- Ban / unban ---

it('bans a user: revokes tokens, soft-deletes, and blocks API login', function () {
    $this->actingAs(User::factory()->admin()->create());

    $user = User::factory()->create([
        'email' => 'victim@example.com',
        'password' => 'secret123!',
    ]);
    $token = $user->createToken('phone')->plainTextToken;

    Livewire::test(ListUsers::class)->callAction(TestAction::make('ban')->table($user));

    // Soft-deleted + tokens revoked.
    expect($user->fresh()->trashed())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    // The old bearer token no longer authenticates.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);

    // A fresh API login is rejected (soft-deleted user is out of scope).
    $this->postJson('/api/v1/auth/login', [
        'email' => 'victim@example.com',
        'password' => 'secret123!',
        'device_name' => 'phone',
    ])->assertStatus(422);
});

it('unbans a soft-deleted user', function () {
    $this->actingAs(User::factory()->admin()->create());

    $user = User::factory()->create();
    $user->delete();

    Livewire::test(ListUsers::class)->callAction(TestAction::make('unban')->table($user));

    expect($user->fresh()->trashed())->toBeFalse();
});

it('prevents an admin from banning themselves', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // Self-ban action is disabled; the account stays active.
    Livewire::test(ListUsers::class)
        ->assertActionDisabled(TestAction::make('ban')->table($admin));

    expect($admin->fresh()->trashed())->toBeFalse();
});
