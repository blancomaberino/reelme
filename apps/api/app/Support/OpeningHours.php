<?php

namespace App\Support;

/**
 * The one place an untrusted opening-hours value becomes `places.opening_hours_json`'s
 * contract shape (T-128): a FLAT LIST OF STRINGS, one human-readable rule per
 * entry, or null. Never Google's `{periods, weekday_text}` object, never an
 * associative `{monday: …}` map — `place.json` pins `string[]` and the mobile
 * client renders the lines verbatim.
 *
 * A neutral leaf on purpose. Both a billable-provider adapter
 * (`App\Services\Geo\BusinessDetails`) and an HTTP resource
 * (`App\Http\Resources\PlaceResource`) need this decision, and neither
 * layer may depend on the other; `App\Support` is the shared floor they may
 * both stand on.
 *
 * TWO METHODS, because a value arriving from OUTSIDE and a value already IN the
 * column want opposite treatment. **This is the whole argument; every call site
 * points here rather than restating it.**
 *
 * The axis is WRITE vs REAPPLY, not literally "a provider" vs "a column": the
 * Filament textarea is a curator's write and takes the strict temper, while
 * PlaceEditor reapplying a suggestion queued before the shape was validated
 * takes the lenient one. Reviewing T-128 found the older wording could send a
 * call site that is neither to the wrong half by pattern-matching the noun.
 *
 * - {@see fromProvider()} is STRICT and all-or-nothing — for every path that
 *   takes a value from a PROVIDER (a Google fetch, a website scrape, a cache
 *   rehydrate). A lenient filter there is actively dangerous: a provider list
 *   with one bad element would yield a SHORTER list, which is still non-empty,
 *   which therefore wins `BusinessEnricher`'s first-non-empty merge and
 *   overwrites good hours already on the place with a truncated copy. Silently
 *   losing half a venue's week is worse than declining to write.
 * - {@see salvage()} is LENIENT — for a value a HUMAN already committed to, or
 *   one already sitting in the column: the read boundary
 *   (`PlaceResource`) and the curated write chokepoint
 *   (`App\Services\Places\PlaceEditor::apply()`). Here the proposal or the row
 *   already exists and the only question is what to do with it. Discarding it
 *   would make hours vanish from a screen with no error anywhere; best-effort
 *   coercion at least shows the curator's own words.
 */
final class OpeningHours
{
    /**
     * Normalize a value a PROVIDER (or a human writing one) supplied, for
     * storage. Blank strings are dropped — a blank line carries no information
     * — but anything else unexpected voids the WHOLE value: a non-array, or a
     * list holding so much as one non-string, yields null rather than a partial
     * list. Nothing left over is null too.
     *
     * EMPTY COLLAPSES TO NULL, NOT `[]`, and the reason is the SERVED payload,
     * not the write. `null` is the contract's "this place has no hours" and the
     * client omits the row; `[]` is a valid `string[]` and renders as an empty
     * hours block — a heading with nothing under it. It is explicitly NOT for
     * `BusinessDetails::toPlacePatch()`'s benefit: that
     * filter drops `[]` and `null` identically, so the distinction is invisible
     * there (an earlier docblock here claimed otherwise).
     *
     * @return list<string>|null
     */
    public static function fromProvider(mixed $value): ?array
    {
        // A LIST, not merely an array. `['monday' => '9-5']` has string values
        // throughout, so the loop below would have accepted it and returned
        // `['9-5']` — the day labels silently gone, and the result non-empty,
        // therefore a winner of BusinessEnricher's first-non-empty merge. That
        // is the same class as truncation, which the loop already refuses: a
        // shape this method does not understand must void the value, not get
        // reinterpreted into a plausible-looking one. `salvage()` is where
        // key-bearing input is coerced rather than dropped, and it PREFIXES the
        // key instead of discarding it.
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $lines = [];
        foreach ($value as $line) {
            if (! is_string($line)) {
                return null; // all-or-nothing: never a truncated week
            }
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return $lines === [] ? null : $lines;
    }

    /**
     * Best-effort coercion of a value ALREADY STORED (a legacy row, a
     * suggestion queued before the shape was validated) to the contract shape.
     * Unlike {@see fromProvider()} this keeps what it can: string elements
     * survive, everything else is skipped, and only an empty result is null.
     *
     * An ASSOCIATIVE array keeps its key IN the line — `{"monday": "9-5"}`
     * becomes `["monday: 9-5"]`, not `["9-5"]`. The key is not decoration: a
     * day-less "9-5" on a place detail reads as "open 9-5 every day", so
     * dropping it is worse than useless. It is only ever prefixed for a string
     * key, so a plain list (integer keys) comes back untouched.
     *
     * @return list<string>|null
     */
    public static function salvage(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $lines = [];
        foreach ($value as $key => $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }
            $label = is_string($key) && trim($key) !== '' ? trim($key).': ' : '';
            $lines[] = $label.trim($line);
        }

        return $lines === [] ? null : $lines;
    }
}
