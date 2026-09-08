<?php

use App\Support\RetentionWindow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled sweep that makes the policy's 14 days a deadline rather than a
 * hope (T-156).
 *
 * Monolog's daily driver prunes as a side effect of writing, so rotation alone
 * keeps a quiet deployment's logs indefinitely. These tests are about the file
 * on disk; the config-to-prose pairing lives in LogRetentionTest.
 *
 * Every path here is derived from `$this->scratch`, captured in `beforeEach`
 * and never re-read from config. `afterEach` runs even when `beforeEach` threw,
 * so a helper that resolved the directory from live config would delete the
 * REAL storage/logs on any setup failure.
 */
beforeEach(function () {
    config()->set('logging.channels.daily.days', 14);

    $this->scratch = storage_path('logs/prune-test-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->scratch);
    config()->set('logging.channels.daily.path', $this->scratch.'/laravel.log');
});

afterEach(function () {
    $scratch = $this->scratch ?? null;

    // Belt and braces: the literal path, and only if it is one of ours.
    if (is_string($scratch) && str_contains($scratch, '/logs/prune-test-')) {
        File::deleteDirectory($scratch);
    }
});

/** Write a log file with a chosen age. Returns its path. */
function pruneTestLog(string $name, int $ageInDays): string
{
    $path = test()->scratch.'/'.$name;
    File::put($path, "a request with ?near=-34.9011,-56.1645 in it\n");
    touch($path, now()->subDays($ageInDays)->getTimestamp());

    return $path;
}

it('deletes a dated log file past the window', function () {
    $old = pruneTestLog('laravel-2026-01-01.log', 20);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($old))->toBeFalse();
});

it('KEEPS a file inside the window, whatever its name says', function () {
    // The excluded row, and the one that matters most: this command deletes
    // logs, so a rule that is one comparison off takes today's data with it.
    // Age is the last WRITE — a file named for January that was appended to
    // yesterday holds yesterday's requests.
    $recent = pruneTestLog('laravel-2026-01-02.log', 1);
    $edge = pruneTestLog('laravel-2026-01-03.log', 13);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($recent))->toBeTrue()
        ->and(File::exists($edge))->toBeTrue();
});

it('takes the bare laravel.log that rotation can never reach', function () {
    // Monolog globs `laravel-*.log`, which does not match `laravel.log`. Any
    // environment that booted before the switch to `daily` keeps that file
    // forever — the exact hole the deployment guide records.
    $abandoned = pruneTestLog('laravel.log', 400);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($abandoned))->toBeFalse();
});

it('prunes a file the LOGGER wrote, not just one this test named', function () {
    // The glob and the channel have to agree. Every other test here types the
    // filename, so all of them stay green if the configured path's basename
    // changes and the command silently matches nothing. This one asks the daily
    // channel to produce the file, then ages it.
    Log::channel('daily')->info('a request with ?near=-34.9011,-56.1645 in it');

    $written = File::glob($this->scratch.'/*.log');
    expect($written)->toHaveCount(1);

    touch($written[0], now()->subDays(30)->getTimestamp());

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($written[0]))->toBeFalse();
});

it('leaves files that are not application logs alone', function () {
    $other = pruneTestLog('horizon-2026-01-01.log', 90);

    $this->artisan('reelmap:logs:prune')->assertSuccessful();

    expect(File::exists($other))->toBeTrue();
});

it('refuses a window below one day instead of deleting today', function () {
    // Fail closed: `LOG_DAILY_DAYS=0` must not be read as "keep nothing". And
    // it must SAY so somewhere a person looks — a scheduled command's console
    // output goes to /dev/null.
    Log::spy();
    config()->set('logging.channels.daily.days', 0);
    $today = pruneTestLog('laravel-2026-01-04.log', 0);

    $this->artisan('reelmap:logs:prune')->assertFailed();

    expect(File::exists($today))->toBeTrue();
    Log::shouldHaveReceived('error')->with('logs.prune_misconfigured', ['days' => 0]);
});

it('reports something it could not delete instead of counting a silent success', function () {
    // `File::delete()` is `@unlink()` with the error suppressed: it returns
    // false and throws nothing. Uncounted, that is a sweep that prunes NOTHING
    // every hour while every signal stays green. A directory standing where a
    // log file should be reproduces it without depending on who owns the box —
    // `unlink` refuses a directory for root too, unlike a permission bit.
    $stuck = $this->scratch.'/laravel-2026-01-05.log';
    File::ensureDirectoryExists($stuck);
    touch($stuck, now()->subDays(40)->getTimestamp());

    $this->artisan('reelmap:logs:prune')->assertFailed();

    expect(File::isDirectory($stuck))->toBeTrue();
});

/** The scheduled events whose command mentions $needle. */
function scheduledEvents(string $needle): Collection
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, $needle));
}

it('is scheduled on every machine, and holds no fleet-wide lock', function () {
    // A command nobody runs is not a retention mechanism. Neither flag may be
    // set: `onOneServer()` obviously prunes one box, and `withoutOverlapping()`
    // does the same thing quietly, because the mutex is sha1(expression +
    // command) in the SHARED cache store with no host component.
    $events = scheduledEvents('reelmap:logs:prune');

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeFalse()
        ->and($events->first()->withoutOverlapping)->toBeFalse()
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('prunes failed_jobs on the window RetentionWindow owns', function () {
    // The other sink holding request data: `failed_jobs.exception` carries a
    // stack trace and `payload` the job's arguments. A window true of the files
    // and false of the database is not a window.
    //
    // Compared against the owner, not against a literal 336 — and NOT against
    // a re-derivation of the same arithmetic, which is the mistake the schedule
    // test above was written to stop making. RetentionWindowTest is where the
    // flooring itself is proven, because the schedule is built at boot and this
    // test cannot move the config it was built from.
    $events = scheduledEvents('queue:prune-failed');

    expect($events)->toHaveCount(1)
        ->and($events->first()->command)->toContain('--hours='.RetentionWindow::hours());
});
