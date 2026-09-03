<?php

use App\Support\WeeklyHours;

/**
 * The weekly lines, generated from structured periods in the reader's language
 * (T-168).
 *
 * The rule under test: **nothing here reads the source's prose.** A Spanish
 * reader saw "Monday: Closed" because the only hours we held were Google's
 * English sentence; the fix is to render the intervals, not to translate the
 * sentence. Every assertion below therefore starts from `{open_day, open_time}`
 * and never from a string.
 */
it('writes the week in the reader’s language', function () {
    $week = [['open_day' => 1, 'open_time' => '12:00', 'close_day' => 1, 'close_time' => '16:00']];

    expect(WeeklyHours::lines($week, 'es')[0])->toBe('Lunes: 12:00 – 16:00');
    // Same row, same data, different reader — the assertion the whole task is for.
    expect(WeeklyHours::lines($week, 'en'))->toContain('Monday: 12:00 PM – 4:00 PM');
});

it('starts the week where the reader’s locale starts it', function () {
    $week = [['open_day' => 1, 'open_time' => '12:00', 'close_day' => 1, 'close_time' => '16:00']];

    // Monday-first in Spanish, Sunday-first in English. Only knowable because the
    // day is an integer: from `weekday_text` the order is the SOURCE locale's and
    // no index is a fixed weekday (T-128).
    expect(WeeklyHours::lines($week, 'es')[0])->toStartWith('Lunes');
    expect(WeeklyHours::lines($week, 'en')[0])->toStartWith('Sunday');
});

it('uses the locale’s own clock, and pads the 24-hour one', function () {
    $lateNight = [['open_day' => 1, 'open_time' => '09:00', 'close_day' => 2, 'close_time' => '00:00']];

    // Padded: CLDR's short pattern for es is `H:mm`, so midnight rendered as
    // "0:00" beside "12:00" — right by the standard, wrong in a column.
    expect(WeeklyHours::lines($lateNight, 'es')[0])->toBe('Lunes: 09:00 – 00:00');
    // NOT padded for the 12-hour locale: "8:00 PM" is how it is actually written.
    expect(WeeklyHours::lines($lateNight, 'en'))->toContain('Monday: 9:00 AM – 12:00 AM');
});

it('says closed from the ABSENCE of a period, not by translating a word', function () {
    // There is no string "Closed" anywhere in the input. The day is closed
    // because the venue listed no interval for it.
    $mondayOnly = [['open_day' => 1, 'open_time' => '12:00', 'close_day' => 1, 'close_time' => '16:00']];
    $lines = WeeklyHours::lines($mondayOnly, 'es');

    expect($lines)->toHaveCount(7);
    expect($lines)->toContain('Martes: Cerrado');
    expect(implode(' ', $lines))->not->toContain('Closed');
});

it('keeps two services on one line, in order', function () {
    $split = [
        ['open_day' => 1, 'open_time' => '12:00', 'close_day' => 1, 'close_time' => '16:00'],
        ['open_day' => 1, 'open_time' => '20:00', 'close_day' => 2, 'close_time' => '00:00'],
    ];

    // The late sitting belongs to the day it OPENS, even though it ends on
    // Tuesday — which is how a reader thinks about Monday night.
    expect(WeeklyHours::lines($split, 'es')[0])->toBe('Lunes: 12:00 – 16:00, 20:00 – 00:00');
    expect(WeeklyHours::lines($split, 'es')[1])->toBe('Martes: Cerrado');
});

it('renders a 24/7 venue as one line, not seven identical ones', function () {
    $always = [['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null]];

    expect(WeeklyHours::lines($always, 'es'))->toBe(['Abierto las 24 h']);
    expect(WeeklyHours::lines($always, 'en'))->toBe(['Open 24 hours']);
});

it('returns null when there is nothing structured to render', function () {
    // The caller then falls back to the source's verbatim prose. Fewer places get
    // localized lines than have hours; none get invented ones.
    expect(WeeklyHours::lines(null, 'es'))->toBeNull();
    expect(WeeklyHours::lines([], 'es'))->toBeNull();
    expect(WeeklyHours::lines('Monday: 9-5', 'es'))->toBeNull();
    // Google's own object, handed in whole by mistake.
    expect(WeeklyHours::lines(['periods' => [], 'weekday_text' => []], 'es'))->toBeNull();
});
