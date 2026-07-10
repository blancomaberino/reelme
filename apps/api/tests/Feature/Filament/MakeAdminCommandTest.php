<?php

use App\Models\User;

it('promotes an existing user to admin', function () {
    $user = User::factory()->create(['email' => 'promote@example.com', 'is_admin' => false]);

    $this->artisan('app:make-admin', ['email' => 'promote@example.com'])
        ->assertExitCode(0);

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('fails when no user matches the email', function () {
    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])
        ->assertExitCode(1);
});

it('is idempotent for an existing admin', function () {
    User::factory()->admin()->create(['email' => 'already@example.com']);

    $this->artisan('app:make-admin', ['email' => 'already@example.com'])
        ->expectsOutputToContain('already an admin')
        ->assertExitCode(0);
});
