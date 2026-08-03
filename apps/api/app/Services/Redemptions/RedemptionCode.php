<?php

namespace App\Services\Redemptions;

use Random\RandomException;

/**
 * The code a diner reads aloud and a staff member types in (T-043, 02 §3.14).
 *
 * Crockford base32, not plain base32 or hex, for one reason: this alphabet is
 * designed to survive a human relay. It omits **I, L, O and U** — the first
 * three because they are indistinguishable from 1 and 0 in most faces at a
 * till, the last so no code can spell an obscenity — and it treats the
 * confusable pairs as equivalent on the way IN. Someone who hears "oh" and
 * types `O` for a `0` still gets a match, because {@see normalize()} folds it.
 *
 * Ten characters (02 §3.14; 06's "8-char" is superseded) over a 32-symbol
 * alphabet is 50 bits — roughly 10^15 codes, so guessing one that is
 * simultaneously live is not a strategy. The code is nonetheless NOT the whole
 * security story: it is a bearer token typed by hand, so the QR carries a signed
 * payload instead ({@see RedemptionQr}), and verification additionally requires
 * a staff account with a verified claim on the place.
 */
final class RedemptionCode
{
    /** Crockford's alphabet: 0-9 A-Z minus I, L, O, U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 10;

    /**
     * A fresh code. Uses `random_int` (CSPRNG) rather than `rand`/`str_shuffle`
     * — a predictable code is a free meal.
     *
     * @throws RandomException
     */
    public static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Fold typed input to the stored form.
     *
     * Handles what actually arrives at a till: the display grouping
     * (`7F3K-92QX-AB`), lower case, stray spaces, and Crockford's confusable
     * substitutions — `O`→`0`, and `I`/`L`→`1`. Without this the diner reads out
     * a code that is objectively correct and the staff member is told it does
     * not exist.
     */
    public static function normalize(string $input): string
    {
        $upper = strtoupper(trim($input));
        $stripped = preg_replace('/[^0-9A-Z]/', '', $upper) ?? '';

        return strtr($stripped, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * Is this the shape of a code at all?
     *
     * Checked before any lookup so a malformed string is a validation failure
     * rather than a database round trip — and so the "not found" path cannot be
     * used to probe timing with arbitrary-length input.
     */
    public static function isWellFormed(string $normalized): bool
    {
        return strlen($normalized) === self::LENGTH
            && strspn($normalized, self::ALPHABET) === self::LENGTH;
    }

    /** Grouped for display: `7F3K-92QX-AB`. Never stored in this form. */
    public static function forDisplay(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}
