<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

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

        return $periods;
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

        $periods = [];

        foreach ($value as $period) {
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

        return $periods === [] ? null : $periods;
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
     * @param  mixed  $periods  the raw column value; salvaged here so callers need not
     * @return array{open_now: bool, closes_at: ?string, opens_at: ?string}|null
     */
    public static function stateAt(mixed $periods, ?string $timezone, ?DateTimeInterface $now = null): ?array
    {
        $zone = self::zone($timezone);
        $schedule = self::salvage($periods);

        if ($zone === null || $schedule === null) {
            return null;
        }

        $local = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable)->setTimezone($zone);
        // `N` is 1 (Monday) … 7 (Sunday); Google's day 0 is Sunday, so 7 maps to 0.
        $minuteOfWeek = ((int) $local->format('N') % 7) * self::DAY
            + (int) $local->format('G') * 60
            + (int) $local->format('i');

        $nextOpen = null;

        foreach ($schedule as $period) {
            $start = $period['open_day'] * self::DAY + self::minutes($period['open_time']);

            // No close at all: the documented 24/7 shape. Open, and there is no
            // closing time to report — not "closes at midnight".
            if ($period['close_day'] === null || $period['close_time'] === null) {
                return ['open_now' => true, 'closes_at' => null, 'opens_at' => null];
            }

            $end = $period['close_day'] * self::DAY + self::minutes($period['close_time']);

            // A close at or before the open crosses midnight (23:00 → 02:00) or
            // wraps the week (Sat 22:00 → Sun 01:00). Push it a full week ahead
            // so the interval stays a forward span, then test `now` in BOTH the
            // current week and the next: an interval that began last Saturday and
            // ends this Sunday morning must still contain Sunday 00:30.
            if ($end <= $start) {
                $end += self::WEEK;
            }

            foreach ([$minuteOfWeek, $minuteOfWeek + self::WEEK] as $candidate) {
                if ($candidate >= $start && $candidate < $end) {
                    return [
                        'open_now' => true,
                        'closes_at' => self::clock($end % self::WEEK),
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

        static $identifiers = null;
        $identifiers ??= array_flip(DateTimeZone::listIdentifiers());

        if (! isset($identifiers[$timezone])) {
            return null;
        }

        return new DateTimeZone($timezone);
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
