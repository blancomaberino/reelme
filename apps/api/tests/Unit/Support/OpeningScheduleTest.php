<?php

use App\Support\OpeningSchedule;

/**
 * The structured half of a place's week (T-155), and the only place an
 * open/closed status is decided.
 *
 * Two things are pinned here above all others, because getting either wrong is
 * worse than shipping no status cue at all:
 *
 * 1. **Unknowable means null, never "closed".** A missing zone, an unusable
 *    zone, or missing periods must yield no cue. A confidently wrong "Closed"
 *    sends someone away from a restaurant that is open.
 * 2. **A malformed close is a parse failure, not a 24/7 venue.** Collapsing the
 *    two would turn a bad payload into "open, always" — the most confidently
 *    wrong answer this class can give.
 */

/** An instant, and the venue-local Google day index (0 = Sunday) it falls on. */
function localDay(string $utc, string $zone): int
{
    $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));

    return ((int) $local->format('N')) % 7;
}

function at(string $utc): DateTimeImmutable
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}

// ---------------------------------------------------------------- fromProvider

it('fromProvider normalizes Google periods to the pinned shape', function () {
    expect(OpeningSchedule::fromProvider([
        ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => '2300']],
        ['open' => ['day' => 2, 'time' => '0930'], 'close' => ['day' => 3, 'time' => '0015']],
    ]))->toBe([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
        ['open_day' => 2, 'open_time' => '09:30', 'close_day' => 3, 'close_time' => '00:15'],
    ]);
});

it('fromProvider reads an ABSENT close as the documented 24/7 shape', function () {
    expect(OpeningSchedule::fromProvider([['open' => ['day' => 0, 'time' => '0000']]]))
        ->toBe([['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null]]);
});

it('fromProvider VOIDS a present-but-malformed close instead of reading it as 24/7', function () {
    // The distinction this test exists for: absent close = never closes; broken
    // close = we did not understand the payload. Treating the second as the
    // first would report a venue as permanently open off a parse failure.
    expect(OpeningSchedule::fromProvider([
        ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => 'later']],
    ]))->toBeNull();

    expect(OpeningSchedule::fromProvider([
        ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 9, 'time' => '2300']],
    ]))->toBeNull();
});

it('fromProvider is all-or-nothing: one bad entry voids the whole week', function () {
    // A truncated week is still non-empty, so it would still win the enricher's
    // first-non-empty merge and overwrite a good schedule (the OpeningHours
    // argument, restated for periods).
    expect(OpeningSchedule::fromProvider([
        ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => '2300']],
        ['open' => ['day' => 2, 'time' => '25:00'], 'close' => ['day' => 2, 'time' => '2300']],
    ]))->toBeNull();
});

it('fromProvider rejects shapes that are not a provider period list', function () {
    expect(OpeningSchedule::fromProvider(null))->toBeNull();
    expect(OpeningSchedule::fromProvider([]))->toBeNull();
    expect(OpeningSchedule::fromProvider('Monday: 11-23'))->toBeNull();
    // Google's whole opening_hours object handed in by mistake.
    expect(OpeningSchedule::fromProvider(['periods' => [], 'weekday_text' => []]))->toBeNull();
    // A period with no usable start describes nothing.
    expect(OpeningSchedule::fromProvider([['close' => ['day' => 1, 'time' => '2300']]]))->toBeNull();
});

it('fromProvider rejects out-of-range days and clocks, booleans included', function () {
    $bad = [
        ['day' => 7, 'time' => '1100'],      // Saturday is 6
        ['day' => -1, 'time' => '1100'],
        ['day' => true, 'time' => '1100'],   // would otherwise cast to 1
        ['day' => '1', 'time' => '1100'],    // string day
        ['day' => 1, 'time' => '2400'],      // 24:00 is not a clock
        ['day' => 1, 'time' => '1160'],      // 60 minutes
        ['day' => 1, 'time' => '11:00'],     // provider format is HHMM
        ['day' => 1, 'time' => 1100],        // not a string
    ];

    foreach ($bad as $open) {
        expect(OpeningSchedule::fromProvider([['open' => $open]]))->toBeNull();
    }
});

// -------------------------------------------------------------------- salvage

it('salvage keeps usable stored entries and drops only the unusable ones', function () {
    // Lenient on READ for the mirror of OpeningHours::salvage()'s reason: the row
    // already exists, so a partial answer is some intervals known, never a
    // partial write that could overwrite something better.
    expect(OpeningSchedule::salvage([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
        ['open_day' => 99, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
        'not a period',
    ]))->toBe([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
    ]);
});

it('salvage drops a HALF close rather than reading the missing side as midnight', function () {
    expect(OpeningSchedule::salvage([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => null],
    ]))->toBeNull();

    expect(OpeningSchedule::salvage([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => null, 'close_time' => '23:00'],
    ]))->toBeNull();
});

it('salvage keeps a full 24/7 entry, where BOTH sides of the close are absent', function () {
    expect(OpeningSchedule::salvage([
        ['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null],
    ]))->toBe([
        ['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null],
    ]);
});

// --------------------------------------------------------------------- stateAt

it('returns NO STATE — not "closed" — when the data cannot support one', function () {
    $week = [['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']];

    expect(OpeningSchedule::stateAt($week, null, at('2026-09-07 15:00')))->toBeNull();
    expect(OpeningSchedule::stateAt($week, '', at('2026-09-07 15:00')))->toBeNull();
    expect(OpeningSchedule::stateAt(null, 'America/Montevideo', at('2026-09-07 15:00')))->toBeNull();
    expect(OpeningSchedule::stateAt([], 'America/Montevideo', at('2026-09-07 15:00')))->toBeNull();
});

it('refuses a fixed offset or an abbreviation as a timezone', function () {
    // The whole point of storing an IANA id: an offset is wrong for half the year
    // anywhere DST applies, so a cue computed from one is wrong half the year.
    $week = [['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']];

    expect(OpeningSchedule::stateAt($week, '+05:00', at('2026-09-07 15:00')))->toBeNull();
    expect(OpeningSchedule::stateAt($week, 'EST', at('2026-09-07 15:00')))->toBeNull();
    expect(OpeningSchedule::stateAt($week, 'Mars/Phobos', at('2026-09-07 15:00')))->toBeNull();
});

it('reports open with its closing time, inside a normal interval', function () {
    // 2026-09-07 18:00 UTC = 15:00 in Montevideo (UTC-3), a Monday.
    $day = localDay('2026-09-07 18:00', 'America/Montevideo');

    expect(OpeningSchedule::stateAt(
        [['open_day' => $day, 'open_time' => '11:00', 'close_day' => $day, 'close_time' => '23:00']],
        'America/Montevideo',
        at('2026-09-07 18:00'),
    ))->toBe(['open_now' => true, 'closes_at' => '23:00', 'opens_at' => null]);
});

it('offers a same-day opening time when closed, and none when the next one is tomorrow', function () {
    $day = localDay('2026-09-07 13:00', 'America/Montevideo'); // 10:00 local, before opening

    expect(OpeningSchedule::stateAt(
        [['open_day' => $day, 'open_time' => '11:00', 'close_day' => $day, 'close_time' => '23:00']],
        'America/Montevideo',
        at('2026-09-07 13:00'),
    ))->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => '11:00']);

    // After closing, the next opening is a different local day. "Opens 11:00"
    // without a weekday would read as "in an hour", so nothing is offered.
    $late = localDay('2026-09-08 02:30', 'America/Montevideo'); // 23:30 local, Monday
    expect(OpeningSchedule::stateAt(
        [['open_day' => $late, 'open_time' => '11:00', 'close_day' => $late, 'close_time' => '23:00']],
        'America/Montevideo',
        at('2026-09-08 02:30'),
    ))->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => null]);
});

it('treats the open minute as open and the close minute as closed', function () {
    $day = localDay('2026-09-07 14:00', 'America/Montevideo'); // 11:00 local exactly
    $week = [['open_day' => $day, 'open_time' => '11:00', 'close_day' => $day, 'close_time' => '23:00']];

    expect(OpeningSchedule::stateAt($week, 'America/Montevideo', at('2026-09-07 14:00'))['open_now'])->toBeTrue();
    // 02:00 UTC next day = 23:00 local, the closing minute itself.
    expect(OpeningSchedule::stateAt($week, 'America/Montevideo', at('2026-09-08 02:00'))['open_now'])->toBeFalse();
});

it('stays open across midnight, including across the week boundary', function () {
    // Saturday 22:00 → Sunday 02:00 in Montevideo. Sunday 00:30 local is inside an
    // interval that started on the LAST day of the week array — the wrap the
    // minute-of-week arithmetic exists for.
    $week = [['open_day' => 6, 'open_time' => '22:00', 'close_day' => 0, 'close_time' => '02:00']];

    // 2026-09-13 03:30 UTC = Sunday 00:30 local.
    expect(OpeningSchedule::stateAt($week, 'America/Montevideo', at('2026-09-13 03:30')))
        ->toBe(['open_now' => true, 'closes_at' => '02:00', 'opens_at' => null]);

    // 2026-09-13 06:00 UTC = Sunday 03:00 local — an hour after it shut.
    expect(OpeningSchedule::stateAt($week, 'America/Montevideo', at('2026-09-13 06:00'))['open_now'])
        ->toBeFalse();
});

it('reports a 24/7 venue as open with no closing time', function () {
    expect(OpeningSchedule::stateAt(
        [['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null]],
        'America/Montevideo',
        at('2026-09-09 07:13'),
    ))->toBe(['open_now' => true, 'closes_at' => null, 'opens_at' => null]);
});

it('refuses an always-open entry sitting beside ordinary trading days', function () {
    // Found by review. The 24/7 branch returned open for ANY period with no
    // close, wherever it sat and whatever day it named — so a week holding a
    // Monday service plus one always-open entry reported the venue open every
    // day, including days it does not trade.
    //
    // The fix is upstream of the arithmetic: Google documents exactly one shape
    // for always-open (a SINGLE period, day 0, 0000, no close), so a null close
    // beside trading days is a payload we cannot read, not a venue to guess at.
    $contradictory = [
        ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => '2300']],
        ['open' => ['day' => 1, 'time' => '0000']],
    ];

    expect(OpeningSchedule::fromProvider($contradictory))->toBeNull();

    // A legacy row already holding that shape keeps its real trading day — the
    // lenient read drops the contradiction, not the week.
    $stored = [
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
        ['open_day' => 1, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null],
    ];

    expect(OpeningSchedule::salvage($stored))->toBe([
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
    ]);
    // Wednesday is therefore closed, where it used to read open.
    expect(OpeningSchedule::stateAt($stored, 'America/Montevideo', at('2026-09-09 18:00'))['open_now'])
        ->toBeFalse();
    expect(OpeningSchedule::stateAt($stored, 'America/Montevideo', at('2026-09-07 18:00'))['open_now'])
        ->toBeTrue();
});

it('fromStored reads the NORMALIZED shape, which fromProvider cannot', function () {
    // The seam a fix round broke and a review caught: `BusinessDetails::toArray()`
    // caches the ALREADY-NORMALIZED list, so rehydrating it with the provider
    // parser reads every entry as malformed and yields null — leaving the column
    // unwritten for every place, silently. Three tempers, three shapes.
    $normalized = [['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']];

    expect(OpeningSchedule::fromStored($normalized))->toBe($normalized);
    expect(OpeningSchedule::fromProvider($normalized))->toBeNull();
});

it('fromStored is all-or-nothing, unlike salvage', function () {
    // Why it is not just salvage(): this value feeds BusinessEnricher's
    // first-non-empty merge, and a shorter-but-non-empty week still wins it —
    // silently deleting a service from a place that had it right.
    $oneBad = [
        ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00'],
        ['open_day' => 99, 'open_time' => 'nope', 'close_day' => null, 'close_time' => null],
    ];

    expect(OpeningSchedule::salvage($oneBad))->toHaveCount(1);
    expect(OpeningSchedule::fromStored($oneBad))->toBeNull();
});

it('refuses a lone close-less period that is not the documented 24/7 shape', function () {
    // Cardinality alone was not enough: ONE trading day whose close never
    // arrived passed as "always open" and reported the venue open at every
    // instant of the week, Sunday 03:00 included.
    $loneMonday = [['open' => ['day' => 1, 'time' => '0900']]];
    expect(OpeningSchedule::fromProvider($loneMonday))->toBeNull();

    // The real shape still works.
    $genuine = [['open' => ['day' => 0, 'time' => '0000']]];
    expect(OpeningSchedule::fromProvider($genuine))->toHaveCount(1);

    // And a stored row of that broken shape does not report open on a Sunday.
    $stored = [['open_day' => 1, 'open_time' => '09:00', 'close_day' => null, 'close_time' => null]];
    expect(OpeningSchedule::stateAt($stored, 'America/Montevideo', at('2026-09-13 06:00')))->toBeNull();
});

it('accepts a backward-compatibility zone id, not only the canonical list', function () {
    // `DateTimeZone::listIdentifiers()` returns the 419 CANONICAL ids and omits
    // 81 aliases this same PHP build constructs happily. Pinning that list would
    // refuse a provider answering with an alias, cache the refusal, and — since
    // enrichment never revisits a place — leave that venue without a cue forever.
    $week = [['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']];
    $alias = 'America/Montreal';

    expect(in_array($alias, DateTimeZone::listIdentifiers(), true))->toBeFalse();
    expect(OpeningSchedule::stateAt($week, $alias, at('2026-09-07 18:00')))->not->toBeNull();

    // Still narrow: an offset or an abbreviation is what this column must never hold.
    expect(OpeningSchedule::stateAt($week, '+05:00', at('2026-09-07 18:00')))->toBeNull();
    expect(OpeningSchedule::stateAt($week, 'EST', at('2026-09-07 18:00')))->toBeNull();
    // UTC is the one legitimate id with no region.
    expect(OpeningSchedule::stateAt($week, 'UTC', at('2026-09-07 18:00')))->not->toBeNull();
});

it('voids a period list longer than a real week', function () {
    // A week is at most two services a day. Anything longer is a malformed
    // payload, and storing it would mean re-validating an unbounded list on every
    // read of the place.
    $tooMany = array_fill(0, 15, ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => '2300']]);
    expect(OpeningSchedule::fromProvider($tooMany))->toBeNull();

    $justFits = array_fill(0, 14, ['open' => ['day' => 1, 'time' => '1100'], 'close' => ['day' => 1, 'time' => '2300']]);
    expect(OpeningSchedule::fromProvider($justFits))->toHaveCount(14);

    // A legacy row written before the cap is bounded on the READ side too.
    $stored = array_fill(0, 40, ['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']);
    expect(OpeningSchedule::salvage($stored))->toHaveCount(14);
});

it('reports closed on a day the week does not cover', function () {
    // Open Mondays only; asked about a Wednesday.
    $monday = localDay('2026-09-07 18:00', 'America/Montevideo');

    expect(OpeningSchedule::stateAt(
        [['open_day' => $monday, 'open_time' => '11:00', 'close_day' => $monday, 'close_time' => '23:00']],
        'America/Montevideo',
        at('2026-09-09 18:00'),
    ))->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => null]);
});

it('answers the same instant differently for venues in different zones', function () {
    // 2026-09-07 18:00 UTC is 15:00 in Montevideo and 20:00 in Madrid. A venue
    // closing at 19:00 local is open in one city and shut in the other.
    $mvd = localDay('2026-09-07 18:00', 'America/Montevideo');
    $mad = localDay('2026-09-07 18:00', 'Europe/Madrid');

    expect(OpeningSchedule::stateAt(
        [['open_day' => $mvd, 'open_time' => '09:00', 'close_day' => $mvd, 'close_time' => '19:00']],
        'America/Montevideo',
        at('2026-09-07 18:00'),
    )['open_now'])->toBeTrue();

    expect(OpeningSchedule::stateAt(
        [['open_day' => $mad, 'open_time' => '09:00', 'close_day' => $mad, 'close_time' => '19:00']],
        'Europe/Madrid',
        at('2026-09-07 18:00'),
    )['open_now'])->toBeFalse();
});

it('follows the zone across a DST change instead of freezing one offset', function () {
    // New York is UTC-4 in July and UTC-5 in January. The SAME wall-clock UTC
    // time therefore lands at 08:30 local in summer and 07:30 in winter, and a
    // venue open 08:00–09:00 is open only in the first. A stored fixed offset
    // would get one of these two answers wrong, every year.
    $summerDay = localDay('2026-07-01 12:30', 'America/New_York');
    $winterDay = localDay('2026-01-14 12:30', 'America/New_York');

    expect(OpeningSchedule::stateAt(
        [['open_day' => $summerDay, 'open_time' => '08:00', 'close_day' => $summerDay, 'close_time' => '09:00']],
        'America/New_York',
        at('2026-07-01 12:30'),
    )['open_now'])->toBeTrue();

    expect(OpeningSchedule::stateAt(
        [['open_day' => $winterDay, 'open_time' => '08:00', 'close_day' => $winterDay, 'close_time' => '09:00']],
        'America/New_York',
        at('2026-01-14 12:30'),
    )['open_now'])->toBeFalse();
});

it('picks the earliest of several intervals in a split day', function () {
    // A lunch/dinner split: closed in the siesta, and the cue names the reopening.
    $day = localDay('2026-09-07 20:00', 'America/Montevideo'); // 17:00 local, between services

    expect(OpeningSchedule::stateAt([
        ['open_day' => $day, 'open_time' => '12:00', 'close_day' => $day, 'close_time' => '15:30'],
        ['open_day' => $day, 'open_time' => '20:00', 'close_day' => $day, 'close_time' => '23:59'],
    ], 'America/Montevideo', at('2026-09-07 20:00')))
        ->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => '20:00']);
});
