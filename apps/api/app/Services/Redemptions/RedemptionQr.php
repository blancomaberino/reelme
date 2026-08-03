<?php

namespace App\Services\Redemptions;

use Illuminate\Support\Facades\Config;

/**
 * The signed payload a QR encodes (T-043, 06 §3).
 *
 * The problem this solves: the code is ten characters a person can type, so it
 * is also ten characters a person can GUESS or shoulder-surf. A QR, by contrast,
 * is scanned by machine and never retyped — so it can carry something longer and
 * unforgeable, and the scan path can require it.
 *
 * The payload is `v1.<code>.<hmac>`, where the HMAC is over the code and the
 * redemption id using the app key. A forged QR built from a guessed code fails
 * {@see verify()} even when the code itself happens to be real, so a scan is
 * strictly stronger evidence than a typed code — which is what lets the verify
 * endpoint accept both without treating them as equally trustworthy.
 *
 * Deliberately NOT a signed route URL: `URL::temporarySignedRoute` bakes in an
 * expiry that would then live in two places (the URL and `expires_at`), and a
 * scanner that opens a URL is a scanner that needs the internet. This is an
 * opaque string the restaurant app posts to the verify endpoint.
 */
final class RedemptionQr
{
    private const VERSION = 'v1';

    /** Truncated to 32 hex chars — 128 bits, well past forgery, and it fits a QR comfortably. */
    private const SIGNATURE_LENGTH = 32;

    public static function sign(string $code, int $redemptionId): string
    {
        return self::VERSION.'.'.$code.'.'.self::signature($code, $redemptionId);
    }

    /**
     * Does this payload genuinely belong to this redemption?
     *
     * `hash_equals`, not `===`: the comparison is against a secret-derived
     * value, and a short-circuiting compare leaks how much of a forgery was
     * right.
     */
    public static function verify(string $payload, string $code, int $redemptionId): bool
    {
        return hash_equals(self::sign($code, $redemptionId), $payload);
    }

    /** The code carried by a payload, or null when it is not one of ours. */
    public static function codeFrom(string $payload): ?string
    {
        $parts = explode('.', $payload);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        return $parts[1];
    }

    private static function signature(string $code, int $redemptionId): string
    {
        $key = (string) Config::get('app.key');

        return substr(hash_hmac('sha256', self::VERSION.'|'.$redemptionId.'|'.$code, $key), 0, self::SIGNATURE_LENGTH);
    }
}
