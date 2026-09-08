<?php

use App\Support\RetentionWindow;

/**
 * The floor, tested where it can actually be moved (T-156).
 *
 * The schedule that consumes this is built at kernel boot, so a test of the
 * registered command string cannot vary the config it was built from — an
 * earlier version of this guard "derived" the expected value inside the test
 * and asserted 336 against 336, which cannot fail. The arithmetic lives in one
 * class precisely so it can be exercised against the values that break it.
 */
it('floors a window that would otherwise mean "delete everything"', function (mixed $configured) {
    // Monolog reads days=0 as keep-forever; `queue:prune-failed --hours=0`
    // prunes everything before now(). Deriving the second from the first
    // without a floor turns one env typo into an emptied failed_jobs table.
    config()->set('logging.channels.daily.days', $configured);

    expect(RetentionWindow::days())->toBe(1)
        ->and(RetentionWindow::hours())->toBe(24);
})->with([
    'empty string (LOG_DAILY_DAYS= with no value)' => [''],
    'zero' => [0],
    'negative' => [-1],
    'non-numeric' => ['forever'],
    'null' => [null],
]);

it('passes a sane window through untouched', function () {
    config()->set('logging.channels.daily.days', 14);

    expect(RetentionWindow::days())->toBe(14)
        ->and(RetentionWindow::hours())->toBe(336);
});

it('reports the raw value separately, so a caller can REFUSE instead of substitute', function () {
    // PruneLogFiles must fail loudly on a bad window rather than sweep files
    // against a silently substituted one. That is why the raw reader exists.
    config()->set('logging.channels.daily.days', 0);

    expect(RetentionWindow::configuredDays())->toBe(0)
        ->and(RetentionWindow::days())->toBe(1);
});
