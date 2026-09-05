<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;

/**
 * The one place a provider's STRUCTURED opening hours become
 * `places.opening_hours_periods_json`'s contract shape, and the one place an
 * open/closed status is computed from them (T-155).
 *
 * Sibling to {@see OpeningHours}, deliberately not merged with it: that class
 * owns the flat `string[]` of human-readable LINES the client renders verbatim
 * (T-128), and this one owns the machine-readable INTERVALS nobody renders. They
 * describe the same week and must never become a union — a value that is
 * sometimes lines and sometimes periods is exactly the shape T-128 removed.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE: a status is returned only when it is a
 * fact. Missing periods, an unusable zone, or a week that does not parse all
 * yield `null` from {@see stateAt()} — never a default of "closed", and never a
 * guess from prose. A confidently wrong "Closed" sends someone away from a
 * restaurant that is open and wanted their business, which is strictly worse
 * than showing no cue at all. Every early return below is that rule.
 *
 * Google's own `open_now` is deliberately NOT plumbed through: it is true at
 * fetch time and a lie for the following 30 days the response is cached.
 */
final class OpeningSchedule
{
    /** Minutes in a day and in a week — the arithmetic below is minute-of-week throughout. */
    private const DAY = 1440;

    private const WEEK = 10080;

    /** A week holds at most two services a day; the rest is a malformed payload. */
    private const MAX_PERIODS = 14;

    /**
     * Normalize a provider's `periods[]` for storage.
     *
     * STRICT AND ALL-OR-NOTHING, for the same reason
     * {@see OpeningHours::fromProvider()} is: a partially-parsed week is still
     * non-empty, so it would still win `BusinessEnricher`'s first-non-empty
     * merge and overwrite a good schedule with a truncated one. Losing half a
     * venue's week silently is worse than declining to write. One malformed
     * entry voids the whole value.
     *
     * Input is Google Place Details' shape:
     * `[{open: {day: 0..6, time: "1100"}, close: {day: 0..6, time: "2300"}}]`,
     * where day 0 is SUNDAY (Google's numbering, kept as-is so no call site has
     * to remember a translation) and `close` is ABSENT for a venue that never
     * closes — the documented way Google expresses 24/7, and the only case in
     * which a null close is meaningful.
     *
     * Output pins one shape:
     * `[{open_day: int, open_time: "HH:MM", close_day: ?int, close_time: ?string}]`
     *
     * @return list<array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}>|null
     */
    public static function fromProvider(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return null;
        }

        // A real week is at most 14 entries (two services a day). Anything past
        // MAX_PERIODS is not a schedule, and storing it would mean re-validating
        // every entry on every read of the place — so it is voided like any other
        // shape this method does not understand.
        if (count($value) > self::MAX_PERIODS) {
            return null;
        }

        $periods = [];

        foreach ($value as $period) {
            if (! is_array($period)) {
                return null;
            }

            $open = self::point($period['open'] ?? null);
            if ($open === null) {
                return null; // a period with no usable start describes nothing
            }

            // An ABSENT close means "never closes". A close that is PRESENT but
            // malformed is a parse failure, not a 24/7 venue — collapsing the
            // two would turn a bad payload into "open right now, always", the
            // most confidently wrong answer this class can give.
            $closeRaw = $period['close'] ?? null;
            $close = null;
            if ($closeRaw !== null) {
                $close = self::point($closeRaw);
                if ($close === null) {
                    return null;
                }
            }

            $periods[] = [
                'open_day' => $open['day'],
                'open_time' => $open['time'],
                'close_day' => $close['day'] ?? null,
                'close_time' => $close['time'] ?? null,
            ];
        }

        // Google documents exactly one shape for "always open": a SINGLE period,
        // day 0, time 0000, with no close. Anything else carrying a null close is
        // a payload we do not understand, and reinterpreting it either way is a
        // guess — read one way it makes the venue open on days it does not trade,
        // read the other it silently drops the entry. Void it, like every other
        // unreadable shape.
        //
        // The SHAPE is checked, not just the count. An earlier version tested
        // cardinality alone, so a lone close-less Monday period — one trading day
        // whose close never arrived — passed as "always open" and reported the
        // venue open at every instant of the week, including Sunday at 03:00.
        if (! self::wellFormedAlwaysOpen($periods)) {
            return null;
        }

        return $periods;
    }

    /**
     * Re-read a value in the STORED shape, all-or-nothing.
     *
     * The third temper, and it exists because the other two answer different
     * questions. {@see fromProvider()} parses GOOGLE's `{open:{day,time}}` shape
     * — handed our own normalized output it reads every entry as malformed and
     * returns null. {@see salvage()} reads the stored shape but leniently, so one
     * bad entry yields a SHORTER week, which is still non-empty and therefore
     * still wins `BusinessEnricher`'s first-non-empty merge.
     *
     * The cached provider payload needs both properties at once: it is already
     * normalized (so the provider parser cannot read it) and it feeds a merge
     * that overwrites a place (so a truncated week must not survive).
     *
     * @return list<array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}>|null
     */
    public static function fromStored(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::MAX_PERIODS) {
            return null;
        }

        $salvaged = self::salvage($value);

        // All-or-nothing: salvage() drops what it cannot read, so a shorter result
        // means something in the list was unreadable and the whole value is void.
        return $salvaged !== null && count($salvaged) === count($value) ? $salvaged : null;
    }

    /**
     * Best-effort re-read of a value ALREADY STORED in the column, for the read
     * boundary — the mirror of {@see OpeningHours::salvage()}, and lenient for
     * the same reason: the row already exists and the only question is whether
     * it can still be used. Unlike the write path this drops unusable entries
     * instead of voiding the value, because a partial schedule read back is a
     * partial ANSWER (some intervals known), never a partial WRITE that could
     * overwrite something better.
     *
     * @return list<array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}>|null
     */
    public static function salvage(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        // Bounded on the read side too: a legacy row written before the cap, or
        // one written by another path, must not make every request re-validate an
        // unbounded list.
        $periods = [];

        foreach (array_slice($value, 0, self::MAX_PERIODS) as $period) {
            if (! is_array($period)) {
                continue;
            }

            $day = self::day($period['open_day'] ?? null);
            $time = self::time($period['open_time'] ?? null);
            if ($day === null || $time === null) {
                continue;
            }

            $closeDay = self::day($period['close_day'] ?? null);
            $closeTime = self::time($period['close_time'] ?? null);

            // Half a close is not a close. Keeping one of the pair would make the
            // interval arithmetic below read a null as midnight and invent a
            // closing time the venue never gave.
            if (($closeDay === null) !== ($closeTime === null)) {
                continue;
            }

            $periods[] = [
                'open_day' => $day,
                'open_time' => $time,
                'close_day' => $closeDay,
                'close_time' => $closeTime,
            ];
        }

        // The stored mirror of the write rule above, and it checks the same
        // SHAPE: a null close means "never closes", which is only credible as the
        // documented single day-0/00:00 entry. Anything else carrying one is
        // contradictory, and the lenient temper drops the contradiction rather
        // than the whole week. Ordered after the parse loop on purpose — an entry
        // that did not parse cannot be judged.
        if (! self::wellFormedAlwaysOpen($periods)) {
            $periods = array_values(array_filter($periods, fn (array $p): bool => $p['close_time'] !== null));
        }

        return $periods === [] ? null : $periods;
    }

    /**
     * The zone id, exactly as given, if this build can construct a real
     * REGION/CITY zone from it — otherwise null.
     *
     * The id is returned rather than a `DateTimeZone` because the caller that
     * needs this ({@see App\Services\Places\OpenPeriodMaterializer}) is
     * copying the value into a column, and it is returned UNCHANGED rather than
     * canonicalized so that the stored id is the same string `places.timezone`
     * holds. Folding an alias to its canonical name here would silently make the
     * two disagree, and the whole point of the copy is that it does not.
     */
    public static function zoneId(?string $timezone): ?string
    {
        return self::zone($timezone) === null ? null : $timezone;
    }

    /**
     * The week's opening spans, as half-open minute-of-week intervals
     * `[open, close)` measured from Sunday 00:00 LOCAL time — or null when the
     * periods are not usable, which is the same "not knowable" contract
     * {@see self::stateAt()} has.
     *
     * This exists so that there is exactly ONE implementation of the schedule's
     * awkward parts — the midnight wrap, the week wrap, and the two ways a
     * venue says it never closes — shared by the two things that need them:
     * `stateAt()`, which answers for one place in PHP, and the
     * `place_open_periods` projection (T-158), which is what lets a LISTING
     * filter on "open now" in SQL without re-deriving any of this. A second
     * spelling of these rules in a query would diverge on the first edge case,
     * and the query is the copy nobody would think to test against the other.
     *
     * A close AT OR BEFORE its open crosses midnight (23:00 → 02:00) or wraps
     * the week (Sat 22:00 → Sun 01:00), so `close` is pushed a full week ahead
     * to keep every span a forward one. That means `close` may exceed a week,
     * and a caller testing containment must therefore test `now` in BOTH the
     * current week and the next — an interval that began last Saturday and ends
     * this Sunday morning must still contain Sunday 00:30.
     *
     * The documented close-less 24/7 shape becomes the single span `[0, WEEK)`.
     * Both normalizers guarantee such an entry is the only one in the list, so
     * collapsing to it loses nothing.
     *
     * @param  mixed  $periods  the raw column value; salvaged here so callers need not
     * @return list<array{0: int, 1: int}>|null
     */
    public static function intervals(mixed $periods): ?array
    {
        $schedule = self::salvage($periods);

        if ($schedule === null) {
            return null;
        }

        $intervals = [];

        foreach ($schedule as $period) {
            // No close at all: the documented 24/7 shape.
            if ($period['close_day'] === null || $period['close_time'] === null) {
                return [[0, self::WEEK]];
            }

            $start = $period['open_day'] * self::DAY + self::minutes($period['open_time']);
            $end = $period['close_day'] * self::DAY + self::minutes($period['close_time']);

            if ($end <= $start) {
                $end += self::WEEK;
            }

            $intervals[] = [$start, $end];
        }

        return $intervals;
    }

    /**
     * The venue's open/closed state at a given instant, or NULL when that is not
     * knowable — which is the important half of this method's contract.
     *
     * Null is returned when there are no usable periods, or no usable IANA zone.
     * Callers must render "no cue", never "closed": the two are different claims
     * and only one of them is supported by the data.
     *
     * `closes_at` is set only while open, `opens_at` only while closed, and
     * `opens_at` only when the next opening falls on the SAME local day —
     * "opens 19:00" is useful, "opens 11:00" three days from now is a lie by
     * omission, and rendering the weekday belongs to the client's locale, not to
     * this method.
     *
     * `$now` is REQUIRED and deliberately has no default. A `new DateTimeImmutable`
     * fallback here would read the system clock and silently ignore the
     * application's — so `travelTo()` could not move it, every call site's cue
     * would be untestable, and the bug would look like a passing test. Callers
     * pass `now()`.
     *
     * @param  mixed  $periods  the raw column value; salvaged here so callers need not
     * @return array{open_now: bool, closes_at: ?string, opens_at: ?string}|null
     */
    public static function stateAt(mixed $periods, ?string $timezone, DateTimeInterface $now): ?array
    {
        $zone = self::zone($timezone);
        $intervals = self::intervals($periods);

        if ($zone === null || $intervals === null) {
            return null;
        }

        $local = DateTimeImmutable::createFromInterface($now)->setTimezone($zone);
        // `N` is 1 (Monday) … 7 (Sunday); Google's day 0 is Sunday, so 7 maps to 0.
        $minuteOfWeek = ((int) $local->format('N') % 7) * self::DAY
            + (int) $local->format('G') * 60
            + (int) $local->format('i');

        $nextOpen = null;

        foreach ($intervals as [$start, $end]) {
            foreach ([$minuteOfWeek, $minuteOfWeek + self::WEEK] as $candidate) {
                if ($candidate >= $start && $candidate < $end) {
                    return [
                        'open_now' => true,
                        // A span of a full week or more is a venue that never
                        // closes, so there is no closing time to report — not
                        // "closes at midnight". {@see self::intervals()} folds
                        // the documented close-less 24/7 shape into exactly such
                        // a span, and a period that states the same thing the
                        // long way (open Sunday 00:00, close Sunday 00:00) now
                        // gets the same answer instead of a spurious "00:00".
                        'closes_at' => $end - $start >= self::WEEK ? null : self::clock($end % self::WEEK),
                        'opens_at' => null,
                    ];
                }
            }

            // Closed. Remember the soonest start still ahead of us today, so a
            // "opens 19:00" can be offered. Anything past local midnight is left
            // to the client's own rendering of the hours lines.
            $ahead = $start >= $minuteOfWeek ? $start : $start + self::WEEK;
            if ($ahead - $minuteOfWeek < self::DAY
                && intdiv($ahead % self::WEEK, self::DAY) === intdiv($minuteOfWeek, self::DAY)
                && ($nextOpen === null || $ahead < $nextOpen)) {
                $nextOpen = $ahead;
            }
        }

        return [
            'open_now' => false,
            'closes_at' => null,
            'opens_at' => $nextOpen === null ? null : self::clock($nextOpen % self::WEEK),
        ];
    }

    /**
     * True when the list carries no close-less entry at all, or carries exactly
     * the one documented always-open shape: a SINGLE period opening on day 0 at
     * 00:00 and never closing.
     *
     * @param  list<array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}>  $periods
     */
    private static function wellFormedAlwaysOpen(array $periods): bool
    {
        $closeless = array_values(array_filter($periods, fn (array $p): bool => $p['close_time'] === null));

        if ($closeless === []) {
            return true; // nothing claims to be always open
        }

        return count($periods) === 1
            && $closeless[0]['open_day'] === 0
            && $closeless[0]['open_time'] === '00:00';
    }

    /**
     * One `{day, time}` endpoint from a provider payload, or null if it is not
     * one. Google sends `time` as a zero-padded 24-hour "HHMM" string; a
     * non-string, a short string, or an out-of-range clock voids it.
     *
     * @return array{day: int, time: string}|null
     */
    private static function point(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $day = self::day($value['day'] ?? null);
        if ($day === null) {
            return null;
        }

        $raw = $value['time'] ?? null;
        if (! is_string($raw) || preg_match('/^([01]\d|2[0-3])([0-5]\d)$/', $raw, $m) !== 1) {
            return null;
        }

        return ['day' => $day, 'time' => $m[1].':'.$m[2]];
    }

    /** Google's 0 (Sunday) … 6 (Saturday). Rejects booleans, which PHP would otherwise cast to 0/1. */
    private static function day(mixed $value): ?int
    {
        if (! is_int($value) || $value < 0 || $value > 6) {
            return null;
        }

        return $value;
    }

    /** A stored "HH:MM", or null. */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * An IANA zone id, or null.
     *
     * `DateTimeZone` would also happily accept "+05:00" and "EST", and both are
     * wrong for this column: a fixed offset is incorrect for half the year
     * wherever DST applies, which is the failure mode this whole task exists to
     * avoid. Only identifiers PHP's own tz database lists are accepted.
     */
    private static function zone(?string $timezone): ?DateTimeZone
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        // A REGION/CITY id that this PHP build can construct — deliberately not
        // `in_array(DateTimeZone::listIdentifiers())`, which returns only the 419
        // CANONICAL ids and rejects the 81 backward-compatibility aliases the
        // same build accepts (America/Montreal and friends). A provider answering
        // with an alias would have been refused, cached as a failure, and — since
        // enrichment never revisits a place that already ran — that venue would
        // never get a cue again.
        //
        // The slash is what keeps this narrow. `DateTimeZone` also accepts
        // "+05:00" and "EST", and both are exactly what this column must never
        // hold: a fixed offset is wrong for half the year wherever DST applies.
        // "UTC" is the one legitimate id without a region.
        if ($timezone !== 'UTC' && ! str_contains($timezone, '/')) {
            return null;
        }

        try {
            return new DateTimeZone($timezone);
        } catch (Exception) {
            return null;
        }
    }

    private static function minutes(string $clock): int
    {
        [$hours, $minutes] = explode(':', $clock);

        return (int) $hours * 60 + (int) $minutes;
    }

    /** A minute-of-week back to a local wall clock. */
    private static function clock(int $minuteOfWeek): string
    {
        $minuteOfDay = $minuteOfWeek % self::DAY;

        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }
}
