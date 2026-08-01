<?php

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (T-068).
 *
 * The cases worth pinning are the ones where a plausible implementation is
 * still insecure: a code that works twice, a recovery code that works twice,
 * a challenge token that turns out to authenticate other endpoints, and a
 * bearer token being enough on its own to strip the factor off an account.
 */
const PASSWORD = 'secret123!';

/** A user with 2FA fully set up. Returns [user, secret]. */
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

describe('setup', function () {
    it('mints a secret and an otpauth URI without enforcing anything yet', function () {
        $user = User::factory()->create(['password' => PASSWORD]);

        $res = $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->assertOk();

        expect($res->json('data.secret'))->toBeString()->not->toBeEmpty()
            ->and($res->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')
            // A ready-to-render PNG, so the mobile setup screen needs no QR
            // library — and therefore no native module, and no dev-client
            // rebuild — to show the code.
            ->and($res->json('data.qr_png'))->toStartWith('data:image/png;base64,')
            // A secret alone must not switch enforcement on: someone who opens
            // the setup screen and walks away must still be able to log in.
            ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => PASSWORD, 'device_name' => 'cli',
        ])->assertOk()->assertJsonPath('data.two_factor_required', null);
    });

    it('enables only after a correct code, and returns recovery codes once', function () {
        $user = User::factory()->create(['password' => PASSWORD]);
        $secret = $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->json('data.secret');

        $this->actingAs($user)
            ->postJson('/api/v1/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);
        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

        $codes = $this->actingAs($user)
            ->postJson('/api/v1/two-factor/confirm', ['code' => totp($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        expect($codes)->toHaveCount(8)
            ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('rolls a new secret if setup is restarted, so a lost phone is recoverable', function () {
        $user = User::factory()->create(['password' => PASSWORD]);

        $first = $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->json('data.secret');
        $second = $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->json('data.secret');

        expect($second)->not->toBe($first);
    });

    it('refuses to re-enable over a live configuration', function () {
        [$user] = withTwoFactor();

        // Otherwise a stolen bearer token could silently swap the secret for one
        // the attacker controls — worse than no second factor at all.
        $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->assertStatus(422);
    });

    it('accepts a code typed with the space the authenticator displays', function () {
        $user = User::factory()->create(['password' => PASSWORD]);
        $secret = $this->actingAs($user)->postJson('/api/v1/two-factor/enable')->json('data.secret');
        $code = totp($secret);

        $this->actingAs($user)->postJson('/api/v1/two-factor/confirm', [
            'code' => substr($code, 0, 3).' '.substr($code, 3),
        ])->assertOk();
    });
});

describe('login enforcement', function () {
    it('withholds the session token and returns a challenge instead', function () {
        [$user] = withTwoFactor();

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => PASSWORD, 'device_name' => 'cli',
        ])->assertOk();

        expect($res->json('data.two_factor_required'))->toBeTrue()
            ->and($res->json('data.challenge_token'))->toBeString()
            // The whole point: a correct password alone yields nothing usable.
            ->and($res->json('data.token'))->toBeNull()
            ->and($user->tokens()->count())->toBe(0);
    });

    it('exchanges a correct TOTP for a real session token', function () {
        [$user, $secret] = withTwoFactor();
        $challenge = loginFor($user);

        $token = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
        ])->assertOk()->json('data.token');

        expect($token)->toBeString();

        // And it is a real token, not just a string that looks like one.
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    });

    it('rejects a wrong code, and says nothing about which half failed', function () {
        [$user] = withTwoFactor();
        $challenge = loginFor($user);

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'code' => '000000', 'device_name' => 'cli',
        ])->assertStatus(422);

        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    it('rejects an unknown or expired challenge token', function () {
        [$user, $secret] = withTwoFactor();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => str_repeat('x', 64), 'code' => totp($secret), 'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('burns a challenge after one successful exchange', function () {
        [$user, $secret] = withTwoFactor();
        $challenge = loginFor($user);

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
        ])->assertOk();

        // Replaying the same challenge must not mint a second session, even
        // with a code that is still inside its window.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('caps guessing against a single challenge, independently of the IP throttle', function () {
        [$user, $secret] = withTwoFactor();
        $challenge = loginFor($user);

        // The route throttle (5/min per IP) is deliberately lifted here: it
        // would mask the per-challenge budget, and an attacker with a pool of
        // addresses is exactly who the second limit exists for.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/two-factor-challenge', [
                'challenge_token' => $challenge, 'code' => '000000', 'device_name' => 'cli',
            ])->assertStatus(422);
        }

        // Six wrong answers burn the challenge — the next attempt is refused
        // even though the code is correct.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'code' => totp($secret), 'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('requires a code or a recovery code, not an empty request', function () {
        [$user] = withTwoFactor();
        $challenge = loginFor($user);

        // This API answers with its own `{error: {code, details}}` envelope
        // (T-092), not Laravel's `{errors}`, so the field errors live under
        // `error.details`.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challenge, 'device_name' => 'cli',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['code', 'recovery_code']]]);
    });

    it('leaves an account without 2FA on the ordinary login path', function () {
        $user = User::factory()->create(['password' => PASSWORD]);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => PASSWORD, 'device_name' => 'cli',
        ])->assertOk();

        expect($res->json('data.token'))->toBeString()
            ->and($res->json('data.two_factor_required'))->toBeNull();
    });
});

describe('replay resistance', function () {
    it('refuses a TOTP code that has already been spent', function () {
        [$user, $secret] = withTwoFactor();
        $code = totp($secret);

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user), 'code' => $code, 'device_name' => 'cli',
        ])->assertOk();

        // A TOTP stays valid for its whole window, so without a burned-window
        // record the very same six digits — shoulder-surfed, or replayed off
        // the wire — would sign in again.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user), 'code' => $code, 'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('still accepts the next window after one is spent', function () {
        // Asserted against the service, not over HTTP: google2fa reads `time()`
        // directly, so Laravel's `travel()` (which only moves Carbon) cannot
        // advance the window — a clock-based version of this test would pass or
        // fail for reasons unrelated to the property. Setting the spent window
        // explicitly states the property exactly: strictly-newer is accepted.
        [$user, $secret] = withTwoFactor();
        $service = app(TwoFactorService::class);
        $now = app(Google2FA::class)->getTimestamp();

        $user->two_factor_last_used_ts = $now - 1;

        // Replay protection must not lock a user out of their own next code.
        expect($service->verifyAndConsume($user, totp($secret)))->toBeTrue()
            ->and($user->two_factor_last_used_ts)->toBe($now)
            // ...and that code is now spent in turn.
            ->and($service->verifyAndConsume($user, totp($secret)))->toBeFalse();
    });
});

describe('recovery codes', function () {
    it('signs in with a recovery code and spends it', function () {
        [$user] = withTwoFactor();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user),
            'recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB',
            'device_name' => 'cli',
        ])->assertOk();

        expect($user->fresh()->two_factor_recovery_codes)->toBe(['CCCCCCCCCC-DDDDDDDDDD']);
    });

    it('refuses a recovery code a second time', function () {
        [$user] = withTwoFactor();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user),
            'recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB',
            'device_name' => 'cli',
        ])->assertOk();

        // A leaked list must burn down one entry at a time, not stay valid.
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user),
            'recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB',
            'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('regenerating invalidates every previous code', function () {
        [$user] = withTwoFactor();

        $fresh = $this->actingAs($user)
            ->postJson('/api/v1/two-factor/recovery-codes/regenerate', ['password' => PASSWORD])
            ->assertOk()->json('data.recovery_codes');

        expect($fresh)->toHaveCount(8)->not->toContain('AAAAAAAAAA-BBBBBBBBBB');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => loginFor($user),
            'recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB',
            'device_name' => 'cli',
        ])->assertStatus(422);
    });

    it('will not show or replace codes without the password', function () {
        [$user] = withTwoFactor();

        $this->actingAs($user)
            ->postJson('/api/v1/two-factor/recovery-codes', ['password' => 'wrong'])
            ->assertStatus(422);
        $this->actingAs($user)
            ->postJson('/api/v1/two-factor/recovery-codes/regenerate', ['password' => 'wrong'])
            ->assertStatus(422);

        expect($user->fresh()->two_factor_recovery_codes)->toContain('AAAAAAAAAA-BBBBBBBBBB');
    });
});

describe('disable', function () {
    it('clears everything once the password is confirmed', function () {
        [$user] = withTwoFactor();

        $this->actingAs($user)
            ->deleteJson('/api/v1/two-factor', ['password' => PASSWORD])
            ->assertOk();

        $user = $user->fresh();
        expect($user->hasTwoFactorEnabled())->toBeFalse()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_recovery_codes)->toBeNull()
            // Cleared too: a stale window would make a later re-enable reject
            // every code until real time caught back up to it.
            ->and($user->two_factor_last_used_ts)->toBeNull();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => PASSWORD, 'device_name' => 'cli',
        ])->assertOk()->assertJsonPath('data.two_factor_required', null);
    });

    it('refuses on a wrong password, so a stolen token alone cannot strip it', function () {
        [$user] = withTwoFactor();

        $this->actingAs($user)
            ->deleteJson('/api/v1/two-factor', ['password' => 'wrong'])
            ->assertStatus(422);

        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });

    it('refuses on a password-less social account rather than waving it through', function () {
        [$user] = withTwoFactor();
        $user->forceFill(['password' => null])->save();

        // `Hash::check` against a null hash must not be reachable — the guard
        // has to reject before it, or a social account's 2FA comes off for free.
        $this->actingAs($user)
            ->deleteJson('/api/v1/two-factor', ['password' => ''])
            ->assertStatus(422);
        $this->actingAs($user)
            ->deleteJson('/api/v1/two-factor', ['password' => 'anything'])
            ->assertStatus(422);

        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
    });
});

describe('secrecy', function () {
    it('never serialises the secret or the codes onto a user payload', function () {
        [$user] = withTwoFactor();

        $body = $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->content();

        expect($body)->not->toContain('two_factor_secret')
            ->and($body)->not->toContain('two_factor_recovery_codes')
            ->and($body)->not->toContain('AAAAAAAAAA-BBBBBBBBBB');
    });

    it('stores the secret and codes encrypted at rest, not in the clear', function () {
        [$user, $secret] = withTwoFactor();

        $raw = DB::table('users')->where('id', $user->id)->first();

        // Readable back through the cast, unreadable in the column — the
        // property that makes a database dump not a 2FA bypass.
        expect($raw->two_factor_secret)->not->toContain($secret)
            ->and($raw->two_factor_recovery_codes)->not->toContain('AAAAAAAAAA-BBBBBBBBBB')
            ->and($user->two_factor_secret)->toBe($secret);
    });

    it('reports state without leaking the secret', function () {
        [$user] = withTwoFactor();

        $res = $this->actingAs($user)->getJson('/api/v1/two-factor')->assertOk();

        expect($res->json('data.enabled'))->toBeTrue()
            ->and($res->json('data.recovery_codes_remaining'))->toBe(2)
            ->and($res->content())->not->toContain('two_factor_secret');
    });
});

describe('the challenge token is not a session', function () {
    it('cannot be used as a bearer token anywhere', function () {
        [$user] = withTwoFactor();
        $challenge = loginFor($user);

        // The single most dangerous way to build this is a half-privileged
        // Sanctum token: every `auth:sanctum` route here authenticates on the
        // token alone without inspecting abilities, so it would be accepted
        // everywhere. It must not be a Sanctum token at all.
        $this->withToken($challenge)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($challenge)->getJson('/api/v1/two-factor')->assertUnauthorized();
    });

    it('cannot be kept alive by guessing wrong', function () {
        [$user, $secret] = withTwoFactor();
        $challenge = loginFor($user);
        $service = app(TwoFactorService::class);

        // Four wrong guesses spread over four minutes. If recording an attempt
        // renewed the TTL, the challenge would still be live at minute 6 and an
        // attacker could hold one open for as long as they kept guessing.
        foreach (range(1, 4) as $minute) {
            $this->travel(1)->minutes();
            expect($service->resolveChallenge($challenge))->not->toBeNull();
        }

        $this->travel(2)->minutes();
        expect($service->resolveChallenge($challenge))->toBeNull();

        $this->travelBack();
    });

    it('is not stored in the cache under its own value', function () {
        [$user] = withTwoFactor();
        $challenge = loginFor($user);

        // Hashed at rest, so read access to the cache does not hand over live
        // bearer values.
        expect(Cache::get('two-factor-challenge:'.$challenge))->toBeNull()
            ->and(app(TwoFactorService::class)->resolveChallenge($challenge))->not->toBeNull();
    });
});
