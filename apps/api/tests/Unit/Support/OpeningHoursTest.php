<?php

use App\Support\OpeningHours;

/**
 * The one normalizer every `opening_hours_json` path shares (T-128). Two
 * methods with deliberately opposite tempers, so both tempers are pinned here:
 * a lenient `fromProvider()` would truncate a venue's week on a WRITE, and a
 * strict `salvage()` would make a legacy row's hours vanish from the SERVED
 * payload with no error anywhere.
 */
it('fromProvider keeps a clean list of lines verbatim, trimmed', function () {
    expect(OpeningHours::fromProvider(['Monday: 9–17', '  Tuesday: Closed  ']))
        ->toBe(['Monday: 9–17', 'Tuesday: Closed']);
});

it('fromProvider rejects a non-array outright', function () {
    expect(OpeningHours::fromProvider(null))->toBeNull();
    expect(OpeningHours::fromProvider('Monday: 9–17'))->toBeNull();
    expect(OpeningHours::fromProvider(42))->toBeNull();
    // Google's own `{periods, weekday_text}` object handed in whole, which is the
    // shape the contract forbids and the mobile client used to look for.
    expect(OpeningHours::fromProvider(['periods' => [], 'weekday_text' => ['Monday: 9–17']]))
        ->toBeNull();
});

it('fromProvider VOIDS an associative map — a shape it cannot read is not reinterpreted', function () {
    // Every VALUE here is a string, so the all-or-nothing loop was satisfied and
    // the method returned ['9-5', '9-5', 'closed'] — a plausible-looking week
    // with the days gone (found by review, T-128). Non-empty, therefore a winner
    // of BusinessEnricher's first-non-empty merge, therefore a silent overwrite
    // of good hours with meaningless ones. Same class as truncation.
    $dayMap = ['monday' => '9-5', 'tuesday' => '9-5', 'sunday' => 'closed'];

    expect(OpeningHours::fromProvider($dayMap))->toBeNull()
        ->and(OpeningHours::fromProvider($dayMap))->not->toBe(['9-5', '9-5', 'closed']);

    // A partially-keyed array is a list to nobody either.
    expect(OpeningHours::fromProvider([0 => 'Monday: 9–17', 'tuesday' => 'Closed']))->toBeNull();

    // salvage() is the half that DOES read keyed input, by keeping the key
    // rather than dropping it — the two halves must not converge.
    expect(OpeningHours::salvage($dayMap))->toBe(['monday: 9-5', 'tuesday: 9-5', 'sunday: closed']);
});

it('fromProvider VOIDS the whole value when one element is not a string — it never truncates', function () {
    // THE point of the strict variant. A filtered result here would be
    // `['Monday: 9–17', 'Wednesday: 9–17']` — non-empty, therefore a winner of
    // BusinessEnricher's first-non-empty merge, therefore a silent overwrite of
    // good hours with five days of the week missing.
    $partial = ['Monday: 9–17', ['open' => '09:00'], 'Wednesday: 9–17'];

    expect(OpeningHours::fromProvider($partial))->toBeNull();
    expect(OpeningHours::fromProvider($partial))->not->toBe(['Monday: 9–17', 'Wednesday: 9–17']);

    // Every non-string type voids it, not just arrays.
    expect(OpeningHours::fromProvider(['Monday: 9–17', null]))->toBeNull();
    expect(OpeningHours::fromProvider(['Monday: 9–17', 5]))->toBeNull();
    expect(OpeningHours::fromProvider(['Monday: 9–17', true]))->toBeNull();
});

it('fromProvider drops blank lines but keeps the rest — a blank carries no information', function () {
    expect(OpeningHours::fromProvider(['Monday: 9–17', '', '   ', "\n", 'Tuesday: Closed']))
        ->toBe(['Monday: 9–17', 'Tuesday: Closed']);
});

it('fromProvider collapses an all-blank/empty value to NULL, not []', function () {
    // null is the contract's "no hours" (the client omits the row); `[]` is a
    // valid string[] that renders as an empty hours block.
    expect(OpeningHours::fromProvider([]))->toBeNull();
    expect(OpeningHours::fromProvider(['', '  ']))->toBeNull();
});

it('salvage keeps the string members of a legacy list and skips the rest', function () {
    // The lenient counterpart: the row already exists, so best-effort beats
    // discarding — the opposite call from fromProvider() on the same input.
    expect(OpeningHours::salvage(['Monday: 9–17', ['open' => '09:00'], 'Wednesday: 9–17']))
        ->toBe(['Monday: 9–17', 'Wednesday: 9–17']);
    expect(OpeningHours::salvage(['Monday: 9–17', null, '', 'Tuesday: Closed']))
        ->toBe(['Monday: 9–17', 'Tuesday: Closed']);
});

it('salvage PRESERVES the key of an associative legacy row', function () {
    // `{"monday": "9-5"}` → `["monday: 9-5"]`, never `["9-5"]`. A day-less
    // "9-5" on a place detail reads as "open 9-5 every day" — dropping the key
    // does not merely lose information, it asserts something false.
    expect(OpeningHours::salvage(['monday' => '9-5', 'tuesday' => 'Closed']))
        ->toBe(['monday: 9-5', 'tuesday: Closed']);
    expect(OpeningHours::salvage(['monday' => '9-5']))->not->toBe(['9-5']);
});

it('salvage leaves a plain list unprefixed, and prefixes only STRING keys in a mixed row', function () {
    // Integer keys are the array's own numbering, not a curator's day label.
    expect(OpeningHours::salvage(['Monday: 9–17', 'Tuesday: Closed']))
        ->toBe(['Monday: 9–17', 'Tuesday: Closed']);
    expect(OpeningHours::salvage([0 => 'Monday: 9–17', 'sun' => 'Closed']))
        ->toBe(['Monday: 9–17', 'sun: Closed']);
});

it('salvage returns null for a non-array and for a row with nothing usable', function () {
    expect(OpeningHours::salvage(null))->toBeNull();
    expect(OpeningHours::salvage('Monday: 9–17'))->toBeNull();
    expect(OpeningHours::salvage([]))->toBeNull();
    expect(OpeningHours::salvage([['open' => '09:00'], null, '  ']))->toBeNull();
});
