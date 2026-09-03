<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use IntlCalendar;
use IntlDateFormatter;

/**
 * The weekly opening-hours lines, GENERATED in the reader's language from the
 * structured periods (T-168).
 *
 * ## Why this exists, and why it is not a translation
 *
 * Until T-155 the only hours we held were Google's `weekday_text` prose, and
 * {@see OpeningHours} renders it verbatim for reasons it documents at length:
 * the language is the SOURCE's, the day ORDER is the source locale's (so no
 * index is a fixed weekday), the meridiem is often absent, and the separators
 * are U+2009/U+202F rather than spaces. Every one of those makes parsing the
 * prose a guess, and a guess about opening hours puts someone at a locked door.
 *
 * So a Spanish reader saw `Monday: Closed`. The fix is NOT to translate that
 * string — deriving Spanish from English prose is the T-128 defect wearing a
 * hat. It is to stop needing the string: `opening_hours_periods_json` holds the
 * same week as machine-readable intervals, where the weekday is an integer and
 * the times are venue-local wall clocks, and from that a correct line can be
 * written in any language.
 *
 * **The timezone is deliberately not a parameter.** `open_time`/`close_time` are
 * already the venue's own wall clock — the zone is what turns them into an
 * instant, which is {@see OpeningSchedule::stateAt()}'s job, not this one's. A
 * place can therefore have readable localized hours before it has a status cue.
 *
 * Unlike its neighbours {@see OpeningHours} and {@see OpeningSchedule}, this one
 * is NOT a framework-free leaf: rendering in a language needs the translator and
 * ICU. That is why its test lives in `tests/Feature`, where the container is
 * booted, rather than beside theirs in `tests/Unit`.
 *
 * Anything this cannot render returns null, and the caller falls back to the
 * source's verbatim prose. Fewer places get localized lines than have hours;
 * none get invented ones.
 */
final class WeeklyHours
{
    /** ICU weekday numbering: Sunday = 1 … Saturday = 7. Google's is Sunday = 0. */
    private const ICU_OFFSET = 1;

    /**
     * One line per day of the week, ordered from the reader's locale's first day,
     * or null when there is nothing structured to render.
     *
     * @return list<string>|null
     */
    public static function lines(mixed $periods, string $locale): ?array
    {
        $schedule = OpeningSchedule::salvage($periods);

        if ($schedule === null) {
            return null;
        }

        // A single close-less entry is the documented always-open shape, and both
        // normalizers guarantee it stands alone. It describes the whole week, so
        // it is one line rather than seven identical ones.
        if (count($schedule) === 1 && $schedule[0]['close_time'] === null) {
            return [trans('places.hours.always_open', [], $locale)];
        }

        $byDay = [];
        foreach ($schedule as $period) {
            // Attributed to the day it OPENS, which is how a reader thinks of a
            // service that runs past midnight: Friday's late sitting is Friday's,
            // even though it ends on Saturday.
            $byDay[$period['open_day']][] = self::range($period, $locale);
        }

        $lines = [];
        foreach (self::daysInLocaleOrder($locale) as $day) {
            $name = self::dayName($day, $locale);
            $lines[] = isset($byDay[$day])
                ? $name.': '.implode(', ', $byDay[$day])
                : $name.': '.trans('places.hours.closed', [], $locale);
        }

        return $lines;
    }

    /**
     * `11:00 – 23:00` in es, `11:00 AM – 11:00 PM` in en — the locale's own short
     * time format, so the 12/24-hour choice is CLDR's rather than a guess of ours.
     *
     * @param  array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}  $period
     */
    private static function range(array $period, string $locale): string
    {
        // A plain en dash with ordinary spaces. Google's own lines use U+2009 and
        // U+202F around theirs, which is invisible in a diff and cost a Maestro
        // flow a run once; ours are ours to keep simple.
        return self::clock($period['open_time'], $locale).' – '.self::clock($period['close_time'] ?? '00:00', $locale);
    }

    private static function clock(string $wallClock, string $locale): string
    {
        [$hours, $minutes] = array_map('intval', explode(':', $wallClock));

        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::SHORT,
            'UTC',
        );

        // Pad the 24-hour form. CLDR's short pattern for es is `H:mm`, so midnight
        // came out as `0:00` beside `12:00` — correct by the standard, and jarring
        // in a column of times. Only the `H` form is padded: `8:00 PM` is how the
        // 12-hour locales actually write it, and forcing `08:00 PM` would be
        // fixing one locale by breaking another.
        $pattern = (string) $formatter->getPattern();
        if (str_contains($pattern, 'H') && ! str_contains($pattern, 'HH')) {
            $formatter->setPattern(str_replace('H', 'HH', $pattern));
        }

        // A fixed date in UTC: only the time-of-day is being formatted, so the day
        // is irrelevant and the zone must not shift it.
        $at = (new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')))->setTime($hours, $minutes);

        // Normalize ICU's exotic spaces to ordinary ones. It emits U+202F (narrow
        // no-break space) before AM/PM, which is the very trap {@see OpeningHours}
        // documents about Google's prose — invisible on screen and in a diff, and
        // it already cost a Maestro flow a run once. Google's strings are the
        // source's and must survive verbatim; THESE are ours, so they get to be
        // predictable for a test, a selector and a search box.
        return str_replace(["\u{202F}", "\u{2009}", "\u{00A0}"], ' ', (string) $formatter->format($at));
    }

    /**
     * Google's day indices (0 = Sunday), ordered from the locale's own first day
     * of the week — Monday in es, Sunday in en-US. Reading a week that starts on
     * the wrong day is the same class of wrongness as reading it in the wrong
     * language, and it is only knowable because the day is an integer here.
     *
     * @return list<int>
     */
    private static function daysInLocaleOrder(string $locale): array
    {
        $first = IntlCalendar::createInstance(new DateTimeZone('UTC'), $locale)->getFirstDayOfWeek();

        return array_map(
            static fn (int $i): int => ($first - self::ICU_OFFSET + $i) % 7,
            range(0, 6),
        );
    }

    /** "Lunes" / "Monday" — capitalized, since these begin a line. */
    private static function dayName(int $googleDay, string $locale): string
    {
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC');
        $formatter->setPattern('EEEE');

        // 2026-01-04 is a Sunday, so adding Google's own index lands on the day.
        $date = (new DateTimeImmutable('2026-01-04', new DateTimeZone('UTC')))->modify("+{$googleDay} days");
        $name = (string) $formatter->format($date);

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
