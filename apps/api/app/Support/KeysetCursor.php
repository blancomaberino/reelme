<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Opaque keyset-pagination cursor for the public index endpoints (03 §1).
 *
 * Laravel's cursorPaginate() cannot order by a raw PostGIS expression (the
 * alias is not addressable in the WHERE it builds), so index endpoints that
 * sort by `ST_Distance` paginate by explicit keyset instead — and every sort
 * uses the same cursor format so mismatches are detectable.
 *
 * A cursor encodes `{s: <sort>, k: [<order key(s)..., id>]}` as base64url
 * JSON. It is deliberately not signed: the values only feed parameterized
 * row-value comparisons, so a tampered cursor can at worst change which page
 * the caller sees — never what they are allowed to see.
 */
final class KeysetCursor
{
    /**
     * @param  list<int|float|string>  $keys
     */
    public static function encode(string $sort, array $keys): string
    {
        $json = (string) json_encode(['s' => $sort, 'k' => $keys]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode a client-supplied cursor, enforcing that it was minted for the
     * same sort (switching sort mid-pagination is a 422, not a 500) and that
     * it carries the expected number of scalar keys.
     *
     * @return list<int|float|string>|null null when no cursor was supplied
     *
     * @throws ValidationException
     */
    public static function decode(?string $cursor, string $sort, int $expectedKeys): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($payload) || ! is_array($payload['k'] ?? null)) {
            self::reject('The cursor is malformed.');
        }
        if (($payload['s'] ?? null) !== $sort) {
            self::reject('The cursor does not match the requested sort.');
        }

        $keys = array_values($payload['k']);
        if (count($keys) !== $expectedKeys || array_filter($keys, fn ($k) => ! is_scalar($k)) !== []) {
            self::reject('The cursor is malformed.');
        }

        return $keys;
    }

    /**
     * Coerce a decoded key that must be an integer (row ids, counts). JSON
     * turns big numbers into floats, and PHP 8.4 THROWS casting a
     * non-representable float to int — so a crafted `1e300` id must 422 like
     * every other malformed cursor, never 500.
     */
    public static function intKey(mixed $key): int
    {
        if (is_int($key)) {
            return $key;
        }
        if (is_string($key) && preg_match('/^-?\d{1,18}$/', $key) === 1) {
            return (int) $key;
        }
        if (is_float($key) && is_finite($key) && floor($key) === $key
            && $key >= (float) PHP_INT_MIN && $key <= (float) PHP_INT_MAX) {
            return (int) $key;
        }

        self::reject('The cursor is malformed.');
    }

    /**
     * Coerce a decoded key that must be a `Y-m-d H:i:s.u` timestamp (the
     * `recent` sort). The value binds into a `?::timestamp` cast, so anything
     * Postgres can't parse would be a 500 — require a strict round-trip
     * (rejecting shape-valid-but-out-of-range values like month 13 that PHP
     * would silently normalize) plus PG's no-year-zero, 422-ing a crafted
     * cursor like every other malformed key. Shared by every `recent`-sorted
     * keyset endpoint so the check can never drift between them.
     */
    public static function timestampKey(mixed $key): string
    {
        $ts = (string) $key;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $ts);
        if ($dt === false || $dt->format('Y-m-d H:i:s.u') !== $ts || str_starts_with($ts, '0000-')) {
            self::reject('The cursor is malformed.');
        }

        return $ts;
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['cursor' => [$message]]);
    }
}
