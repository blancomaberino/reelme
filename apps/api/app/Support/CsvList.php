<?php

namespace App\Support;

/**
 * The one place a comma-separated query parameter (`?include=a,b`,
 * `?types=a,b`) becomes a list of members.
 *
 * A neutral leaf: two FormRequests need this parse and neither may depend on
 * the other. They had the same six-token expression each, down to the
 * `is_string` guard — and that guard is not decoration, it is a fix. The
 * `string` validation rule cannot protect the cast, because `withValidator()`'s
 * `after` closure runs even when an earlier rule already failed: `?include[]=x`
 * reached `(string) $array`, raised a PHP warning that Laravel's handler
 * promoted to an ErrorException, and returned a 500 on a public endpoint where
 * a 422 belongs (see ArrayQueryParamTest). Two copies of a fix is one copy that
 * will be missing the next time.
 */
final class CsvList
{
    /**
     * Split a raw query value into its trimmed, deduped, non-empty members.
     *
     * Returns NULL — not `[]` — when the parameter is absent, blank, or not a
     * string, because that is the case each caller answers differently: an
     * absent `include` means "embed nothing", an absent `types` means "every
     * type". `[]` stays available for its own meaning, "the caller sent
     * separators and no members", which both callers treat as an explicit
     * empty set.
     *
     * @return list<string>|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // `array_filter` with no callback drops every FALSEY member, and "0" is
        // falsey: `?include=0` would have been filtered to an empty set and
        // silently accepted instead of failing the unknown-include 422, and
        // `?types=0` would have meant "every type" instead of "the type 0".
        // Only the empty string is not a member.
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $member): bool => $member !== '',
        )));
    }
}
