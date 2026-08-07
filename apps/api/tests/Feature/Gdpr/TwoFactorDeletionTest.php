<?php

use App\Models\User;
use App\Services\Gdpr\AccountDeletion;

/**
 * The deletion gate on the 2FA login path (T-050).
 *
 * `LoginController` returns a challenge instead of a token when 2FA is on, so
 * for those accounts the SECOND controller is the one that actually mints a
 * session — and `TwoFactorService::resolveChallenge()` had to be widened to
 * `withTrashed()` for a pending deletion to be cancellable at all. Both halves
 * shipped with no coverage; these are the four directions that matter.
 *
 * Its own file rather than another `describe()` in TwoFactorTest: that file is
 * already deep enough that one more nested closure trips PHP's compile-time
 * stack limit in the container.
 */
it('lets a 2FA user cancel their deletion by completing the challenge', function () {
    [$user, $secret] = withTwoFactor();
    app(AccountDeletion::class)->request($user);

    $challenge = loginFor($user->fresh());

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);

    // Revert `resolveChallenge()` to `User::find()` and this fails: the user is
    // locked out of their own cancellation path, told only that their code was
    // wrong, with no way back before the purge runs.
    $restored = User::withTrashed()->find($user->id);
    expect($restored->trashed())->toBeFalse()
        ->and($restored->deletion_requested_at)->toBeNull();
});

it('does not restore the account on the password step alone', function () {
    [$user] = withTwoFactor();
    app(AccountDeletion::class)->request($user);

    loginFor($user->fresh());

    // The stated reason `cancel()` sits AFTER the 2FA branch: a stolen password
    // must not be enough to undo a deletion its owner asked for. Move that call
    // one block earlier and this is the test that notices.
    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

it('rejects the challenge for a BANNED 2FA account', function () {
    [$user, $secret] = withTwoFactor();
    $challenge = loginFor($user);

    // Banned after the challenge was issued — a soft delete with no deletion
    // request behind it. Exchanging it would hand a banned user a live session
    // through the back half of the login, bypassing the gate on the front half.
    $user->delete();

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
    ])->assertStatus(422);

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

it('rejects the challenge once the grace period has lapsed', function () {
    config(['gdpr.purge_grace_days' => 14]);
    [$user, $secret] = withTwoFactor();
    $challenge = loginFor($user);

    // A challenge issued just before the window closed must not still be
    // exchangeable after it — the two controllers have to read the same clock.
    $user->forceFill([
        'deleted_at' => now()->subDays(20),
        'deletion_requested_at' => now()->subDays(20),
    ])->saveQuietly();

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
    ])->assertStatus(422);
});
