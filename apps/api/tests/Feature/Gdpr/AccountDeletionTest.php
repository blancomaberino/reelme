<?php

use App\Jobs\Gdpr\PurgeUserData;
use App\Models\User;
use App\Services\Gdpr\AccountDeletion;
use App\Services\Gdpr\UserDataPurger;
use Illuminate\Support\Facades\Queue;

/**
 * DELETE /me — the request side of erasure (T-050, NFR-10).
 *
 * The thing worth testing here is not that a row gets a `deleted_at`. It is
 * that the two halves of "deleted" happen at the right times: the session dies
 * NOW, the data dies LATER, and the window in between is genuinely reversible
 * without being a way back into an account that is already gone.
 */
beforeEach(function () {
    Queue::fake();
});

it('ends every session immediately and schedules the purge', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');

    $response = $this->withToken($token)->deleteJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.grace_days', config('gdpr.purge_grace_days'));

    expect($user->fresh()->trashed())->toBeTrue()
        // BOTH tokens, not just the calling one. A deletion that left the
        // tablet signed in would be a deletion in name only.
        ->and($user->tokens()->count())->toBe(0);

    // And the credential really is dead on the NEXT request, not merely absent
    // from a table — the middleware has to agree. forgetGuards first: the
    // sanctum guard caches its resolved user for the life of the container, and
    // in a test that container spans both requests, so without this the second
    // call is answered from the first one's memory and passes no matter what.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    Queue::assertPushed(PurgeUserData::class, fn ($job) => $job->userId === $user->id);
});

it('delays the purge by the configured grace period', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();

    Queue::assertPushed(PurgeUserData::class, function ($job) {
        // The irreversible half must not be able to run today. Without a delay
        // the job is picked up within seconds and the grace period is a lie
        // told by the response body.
        return $job->delay?->greaterThan(now()->addDays(13)) === true;
    });
});

it('does not queue a second purge when asked twice', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    // Same user, already trashed — the second call must be a no-op rather than
    // restarting the clock or stacking another erasure.
    app(AccountDeletion::class)->request($user->fresh());

    Queue::assertPushed(PurgeUserData::class, 1);
});

it('lets the owner sign back in during the grace period, cancelling the deletion', function () {
    $user = User::factory()->create(['email' => 'regret@test.dev', 'password' => bcrypt('secret-password')]);

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    expect($user->fresh()->trashed())->toBeTrue();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'regret@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['token']]);
    expect($user->fresh()->trashed())->toBeFalse();
});

it('refuses the login once the grace period has lapsed', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create(['email' => 'gone@test.dev', 'password' => bcrypt('secret-password')]);

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    // Past the window: the purge is due or done, so "signing back in" would
    // restore a shell of an account whose data no longer exists.
    $user->forceFill([
        'deleted_at' => now()->subDays(15),
        'deletion_requested_at' => now()->subDays(15),
    ])->saveQuietly();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'gone@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ])->assertStatus(422);

    expect($user->fresh()->trashed())->toBeTrue();
});

it('skips a purge whose deletion was cancelled', function () {
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);
    app(AccountDeletion::class)->cancel($user->fresh());

    // The queued job cannot be recalled once dispatched, so the guard inside it
    // is the only thing standing between a cancelled deletion and an erased
    // account. Run the real job to prove that guard holds.
    (new PurgeUserData($user->id))->handle(
        app(UserDataPurger::class),
        app(AccountDeletion::class),
    );

    $user->refresh();
    expect($user->trashed())->toBeFalse()
        ->and($user->email)->not->toContain('reelmap.invalid');
});

it('does not let a BANNED account sign itself back in', function () {
    // A ban is also a soft delete. When the grace period first shipped it keyed
    // on `deleted_at` alone, which made "sign back in to cancel your deletion"
    // into a self-service unban — caught by the existing ban suite, not by any
    // test written for this feature. `deletion_requested_at` is what separates
    // the two states, and this is the case that keeps them separate.
    $user = User::factory()->create(['email' => 'banned@test.dev', 'password' => bcrypt('secret-password')]);
    $user->tokens()->delete();
    $user->delete();

    expect($user->fresh()->deletion_requested_at)->toBeNull();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'banned@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ])->assertStatus(422);

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

it('does not purge a banned account', function () {
    // Same conflation, opposite direction: a ban is a moderation decision, not
    // a request to be erased. Running the purge on one would destroy the very
    // evidence the ban was based on.
    $user = User::factory()->create(['name' => 'Banned Person']);
    $user->delete();

    (new PurgeUserData($user->id))->handle(
        app(UserDataPurger::class),
        app(AccountDeletion::class),
    );

    expect(User::withTrashed()->find($user->id)->name)->toBe('Banned Person');
});

it('rejects both data-rights endpoints for a caller with no session', function () {
    $this->deleteJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/me/export')->assertUnauthorized();
});
