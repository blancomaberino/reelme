<?php

namespace App\Console\Commands;

use App\Support\RetentionWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Delete application log files past the retention period the policy states (T-156).
 *
 * Monolog's `daily` driver already prunes — but only as a side effect of
 * WRITING: the rotating handler drops old files when it opens a new day's file,
 * so a deployment that goes quiet keeps its logs until traffic returns. The
 * privacy policy does not say "14 days, unless nobody visits". It says the file
 * is deleted, and `?near=lat,lng` puts a user's coordinates in it.
 *
 * So the schedule enforces what rotation only approximates. Two files matter:
 *
 * - `laravel-YYYY-MM-DD.log` — the dated files rotation would eventually take.
 * - `laravel.log` — the bare file from a `single`-era boot, which rotation NEVER
 *   takes because Monolog globs the dated pattern and this does not match it.
 *   The `emergency` channel still writes there, so it is pruned by age like any
 *   other: untouched for the whole window means it holds nothing recent.
 *
 * Age is the file's last WRITE, not the date in its name: a file appended to
 * today is today's data whatever it is called.
 *
 * A deletion that FAILS is the dangerous case, because the failure is quiet. A
 * run that could not delete anything must not look like a run that found
 * nothing, so per-file failures are counted, logged, and returned as a non-zero
 * exit. A failure to READ the directory at all is left to throw: the child
 * process dies non-zero, `onFailure` fires, and the tracker gets it — louder
 * than a counter, which is what an unreadable log directory deserves.
 *
 * On a box idle for the whole window the CURRENT file is itself past the cutoff
 * and is unlinked while php-fpm still holds the stream open; writes go to the
 * unlinked inode until the next rotation reopens it. That is at most a day of
 * logs, on a box with no traffic to log.
 */
class PruneLogFiles extends Command
{
    protected $signature = 'reelmap:logs:prune';

    protected $description = 'Delete application log files older than LOG_DAILY_DAYS (T-156)';

    public function handle(): int
    {
        // The RAW value, not RetentionWindow::days(): a misconfigured window must
        // make this sweep fail loudly rather than quietly act on a substituted
        // one. The floored value is for the consumer that cannot refuse.
        $days = RetentionWindow::configuredDays();

        // A misconfigured window must not be read as "delete everything". Zero
        // or negative would make the cutoff now-or-later and take today's file
        // out from under the running process. `LOG_DAILY_DAYS=` empty casts to
        // 0, which is the likeliest way to arrive here.
        if ($days < 1) {
            // Log, not just $this->error(): a scheduled command's output goes
            // to /dev/null, so the console line reaches nobody. Same reason
            // VerifyLedgerInvariants logs before it returns FAILURE.
            Log::error('logs.prune_misconfigured', ['days' => $days]);

            $this->error('logging.channels.daily.days must be at least 1; nothing pruned.');

            return self::FAILURE;
        }

        // Both the directory AND the filename prefix come from the configured
        // path. Deriving only the directory and hardcoding "laravel" would let
        // a renamed channel prune nothing while every test stayed green.
        $configured = (string) config('logging.channels.daily.path');
        $directory = dirname($configured);
        $prefix = pathinfo($configured, PATHINFO_FILENAME);

        if (! File::isDirectory($directory)) {
            $this->info('No log directory yet; nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $deleted = 0;
        $failed = 0;

        foreach (File::glob($directory.'/'.$prefix.'*.log') ?: [] as $path) {
            try {
                if (File::lastModified($path) >= $cutoff) {
                    continue;
                }

                if (File::delete($path)) {
                    $deleted++;

                    continue;
                }

                // `File::delete()` is `@unlink()` inside a catch that returns
                // false, so a permission error never reaches the catch below.
                $reason = 'delete refused — permissions, or not a regular file';
            } catch (\Throwable $e) {
                // A file can vanish between the glob and the stat (a concurrent
                // rotation). One unreadable path must not strand the rest.
                $reason = $e->getMessage();
            }

            // Every path that reaches here assigned `$reason` — PHPStan proves
            // it, which is why there is no `??` fallback. It is function-scoped,
            // so a future branch inside the try that falls through WITHOUT
            // assigning would log the previous file's reason under this path;
            // the analyser is what stops that, not a default value that would
            // hide it.
            $failed++;
            Log::warning('logs.prune_failed', ['path' => basename($path), 'reason' => $reason]);
        }

        // Unconditional, so "found nothing to do" and "could not do it" are
        // distinguishable in the log without reading the code.
        Log::info('logs.pruned', ['deleted' => $deleted, 'failed' => $failed, 'days' => $days]);

        $this->info("Deleted {$deleted} log file(s) older than {$days} days; {$failed} could not be deleted.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
