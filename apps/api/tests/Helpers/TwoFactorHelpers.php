<?php

/*
|--------------------------------------------------------------------------
| Shared two-factor test helpers
|--------------------------------------------------------------------------
| Loaded from Pest.php rather than declared in TwoFactorTest.php, because a
| helper defined inside a test FILE only exists once that file is compiled —
| so any sibling suite that wants a 2FA user has to either duplicate it or be
| run in the same process. (And appending yet another describe() block to that
| file to avoid the problem hits PHP's compile-time stack limit, which is how
| this extraction got made.)
*/

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

const PASSWORD = 'secret123!';

/**
 * A user with 2FA fully set up.
 *
 * @return array{0: User, 1: string} the user and their TOTP secret
 */
function withTwoFactor(): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create(['password' => PASSWORD]);
    $user->two_factor_secret = $secret;
    $user->two_factor_recovery_codes = ['AAAAAAAAAA-BBBBBBBBBB', 'CCCCCCCCCC-DDDDDDDDDD'];
    $user->two_factor_confirmed_at = now();
    $user->save();

    return [$user->fresh(), $secret];
}

function totp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** Password step only — returns the challenge token. */
function loginFor(User $user): string
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => PASSWORD,
        'device_name' => 'cli',
    ])->assertOk()->json('data.challenge_token');
}
