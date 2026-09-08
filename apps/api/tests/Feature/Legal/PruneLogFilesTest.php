<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/**
 * The scheduled sweep that makes the policy's 14 days a deadline rather than a
 * hope (T-156).
 *
 * Monolog's daily driver prunes as a side effect of writing, so rotation alone
 * keeps a quiet deployment's logs indefinitely — CodeRabbit's read of the
 * published sentence, and correct. These tests are about the file on disk, not
 * about the config: the config test lives in LogRetentionTest.
 */
function logDir(): string
{
    return dirname((string) config('logging.channels.daily.path'));
}

function writeLog(string $name, int $ageInDays): string
{
    $path = logDir().'/'.$name;
    File::ensureDirectoryExists(logDir());
    File::put($path, "a request with ?near=-34.9011,-56.1645 in it\n");
    touch($path, now()->subDays($ageInDays)->getTimestamp());

    return $path;
}

beforeEach(function () {
    config()->set('logging.channels.daily.days', 14);

    // A scratch directory, so the suite never deletes the log it is writing to.
    $dir = storage_path('logs/prune-test-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($dir);
    config()->set('logging.channels.daily.path', $dir.'/laravel.log');
});

afterEach(function () {
    File::deleteDirectory(logDir());
});

it('deletes a dated log file past the window', function () {
    $old = writeLog('laravel-2026-01-01.log', 20);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($old))->toBeFalse();
});

it('KEEPS a file inside the window, whatever its name says', function () {
    // The excluded row, and the one that matters most: this command deletes
    // logs, so a rule that is one comparison off takes today's data with it.
    // Age is the last WRITE — a file named for January that was appended to
    // yesterday holds yesterday's requests.
    $recent = writeLog('laravel-2026-01-02.log', 1);
    $edge = writeLog('laravel-2026-01-03.log', 13);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($recent))->toBeTrue()
        ->and(File::exists($edge))->toBeTrue();
});

it('takes the bare laravel.log that rotation can never reach', function () {
    // Monolog globs `laravel-*.log`, which does not match `laravel.log`. Any
    // environment that booted before the switch to `daily` keeps that file
    // forever — the exact hole the deployment guide records.
    $abandoned = writeLog('laravel.log', 400);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($abandoned))->toBeFalse();
});

it('leaves files that are not application logs alone', function () {
    $other = writeLog('horizon-2026-01-01.log', 90);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($other))->toBeTrue();
});

it('refuses a window below one day instead of deleting today', function () {
    // Fail closed: `LOG_DAILY_DAYS=0` must not be read as "keep nothing".
    config()->set('logging.channels.daily.days', 0);
    $today = writeLog('laravel-2026-01-04.log', 0);

    $this->artisan('reelmap:logs:prune')->assertFailed();

    expect(File::exists($today))->toBeTrue();
});

it('is scheduled, on every machine, because logs are per-machine files', function () {
    // A command nobody runs is not a retention mechanism. And unlike the DB
    // sweeps beside it, `onOneServer()` would be a bug here: it would prune one
    // box and leave the others holding coordinates past the published window.
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'reelmap:logs:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeFalse();
});
