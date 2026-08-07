<?php

namespace App\Services\Auth;

use App\Models\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (T-068).
 *
 * Three properties this class exists to guarantee, none of which a bare
 * `verify($secret, $code)` gives you:
 *
 * 1. **No replay.** A TOTP code is valid for its whole window, so the same six
 *    digits work more than once — long enough for anyone who read them off a
 *    screen to reuse them. Verification records the accepted window and only
 *    ever accepts a strictly newer one.
 * 2. **Recovery codes are single-use.** A used code is removed from the stored
 *    set, not merely marked, so a leaked list degrades one code at a time.
 * 3. **The login challenge is not a session.** The token handed out between
 *    "password was right" and "second factor was right" is a random cache key,
 *    NOT a Sanctum token — see {@see issueChallenge()}.
 */
class TwoFactorService
{
    /**
     * Accept the adjacent window either side of now, so a phone whose clock is
     * a little off still works. One step, not more: each extra step widens the
     * period an observed code stays usable.
     */
    private const WINDOW = 1;

    /** Long enough to fetch a code from an authenticator, short enough to matter. */
    private const CHALLENGE_TTL_SECONDS = 300;

    /** Wrong codes allowed against one challenge before it is burned. */
    private const CHALLENGE_MAX_ATTEMPTS = 5;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The label carries the account identity and the issuer the app name, which
     * is what stops a user with several accounts seeing a list of
     * indistinguishable six-digit codes.
     */
    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * Verify a TOTP code and burn its window.
     *
     * Returns false for a code from a window at or before the last accepted one,
     * so a correct code cannot be replayed even while it is still notionally
     * valid. The caller must persist $user — the timestamp is only set here.
     */
    public function verifyAndConsume(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $timestamp = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_used_ts,
            self::WINDOW,
        );

        if ($timestamp === false) {
            return false;
        }

        // verifyKeyNewer returns `true` (not a timestamp) when there was no
        // previous timestamp to compare against — normalise so the next call
        // always has a real window to be newer than.
        $user->two_factor_last_used_ts = $timestamp === true
            ? $this->google2fa->getTimestamp()
            : $timestamp;

        return true;
    }

    /**
     * The same URI rendered as a PNG data URI, ready to drop into an `<Image>`.
     *
     * Rendered server-side on purpose: the obvious alternative is a QR library
     * on the device, but every React Native one draws through react-native-svg —
     * a native module, so adding it forces a full dev-client rebuild. A ~1KB PNG
     * data URI renders in the stock `Image` component and costs the mobile side
     * no new dependency at all.
     */
    public function qrCodePng(User $user, string $secret): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
        ]);

        return (new QRCode($options))->render($this->otpauthUri($user, $secret));
    }

    /**
     * A recovery code is written on paper and typed back months later, so the
     * alphabet drops the characters people confuse — O/0, I/1/L, S/5 — and the
     * code is split into two groups to be readable aloud.
     *
     * Drawn uniformly from that alphabet rather than by upper-casing
     * `Str::random()`, which would fold a-z onto A-Z and leave letters twice as
     * likely as digits. 20 characters from 26 symbols is ~94 bits, and online
     * guessing is capped long before that matters.
     */
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn (): string => $this->recoveryGroup().'-'.$this->recoveryGroup())
            ->values()->all();
    }

    private function recoveryGroup(): string
    {
        $max = strlen(self::RECOVERY_ALPHABET) - 1;

        return collect(range(1, 10))
            ->map(fn (): string => self::RECOVERY_ALPHABET[random_int(0, $max)])
            ->implode('');
    }

    /**
     * Spend a recovery code. Returns false if it is not in the user's set.
     *
     * The comparison is constant-time and the match is removed rather than
     * flagged, so a code cannot be reused and a leaked list burns down one entry
     * at a time. The caller must persist $user.
     */
    public function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $code) {
            if (hash_equals($code, $candidate)) {
                unset($codes[$index]);
                $user->two_factor_recovery_codes = array_values($codes);

                return true;
            }
        }

        return false;
    }

    /**
     * Mint the token that stands between a correct password and a full session.
     *
     * Deliberately NOT a Sanctum token with a restricted ability: every
     * `auth:sanctum` route in this app authenticates on the token alone without
     * inspecting abilities, so a half-authenticated Sanctum token would be
     * accepted everywhere. A random cache key bound to a user id cannot
     * authenticate anything — it is only meaningful to
     * {@see consumeChallenge()}.
     */
    public function issueChallenge(User $user): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addSeconds(self::CHALLENGE_TTL_SECONDS);

        Cache::put(
            $this->challengeKey($token),
            // The deadline is carried in the entry, not left implicit in the
            // cache TTL, so recording an attempt can rewrite the entry without
            // silently granting it a fresh five minutes.
            ['user_id' => $user->id, 'attempts' => 0, 'expires_at' => $expiresAt->getTimestamp()],
            $expiresAt,
        );

        return $token;
    }

    /**
     * Resolve a challenge token to its user, counting a failed attempt.
     *
     * Returns null when the token is unknown, expired, or has burned through its
     * attempt budget — which caps guessing against one challenge independently
     * of the per-IP throttle on the route.
     */
    public function resolveChallenge(string $token): ?User
    {
        /** @var array{user_id: int, attempts: int, expires_at: int}|null $entry */
        $entry = Cache::get($this->challengeKey($token));

        if ($entry === null) {
            return null;
        }

        $expiresAt = Carbon::createFromTimestamp($entry['expires_at']);

        if ($entry['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS || $expiresAt->isPast()) {
            Cache::forget($this->challengeKey($token));

            return null;
        }

        Cache::put(
            $this->challengeKey($token),
            ['user_id' => $entry['user_id'], 'attempts' => $entry['attempts'] + 1, 'expires_at' => $entry['expires_at']],
            // Re-put against the ORIGINAL deadline. Passing a fresh TTL here
            // would let an attacker hold a challenge open indefinitely by
            // guessing wrong just often enough to keep renewing it.
            $expiresAt,
        );

        // withTrashed: an account inside its GDPR deletion grace period can be
        // signed back into — that is how the deletion gets cancelled (T-050).
        // Without this the 2FA half of that login resolves to null and the user
        // is told their code was wrong, with no way to reach their own account.
        return User::withTrashed()->find($entry['user_id']);
    }

    /** Invalidate a challenge once it has been successfully exchanged. */
    public function consumeChallenge(string $token): void
    {
        Cache::forget($this->challengeKey($token));
    }

    private function challengeKey(string $token): string
    {
        // Hashed so the cache store never holds the bearer value itself.
        return 'two-factor-challenge:'.hash('sha256', $token);
    }
}
