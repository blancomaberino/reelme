<?php

namespace App\Console\Commands;

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
 */
class PruneLogFiles extends Command
{
    protected $signature = 'reelmap:logs:prune';

    protected $description = 'Delete application log files older than LOG_DAILY_DAYS (T-156)';

    public function handle(): int
    {
        $days = (int) config('logging.channels.daily.days');

        // A misconfigured window must not be read as "delete everything". Zero
        // or negative would make the cutoff now-or-later and take today's file
        // out from under the running process.
        if ($days < 1) {
            $this->error('logging.channels.daily.days must be at least 1; nothing pruned.');

            return self::FAILURE;
        }

        $directory = dirname((string) config('logging.channels.daily.path'));

        if (! File::isDirectory($directory)) {
            $this->info('No log directory yet; nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach (File::glob($directory.'/laravel*.log') as $path) {
            try {
                if (File::lastModified($path) >= $cutoff) {
                    continue;
                }

                if (File::delete($path)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // A file can vanish between the glob and the stat (a concurrent
                // rotation). One unreadable path must not strand the rest.
                Log::warning('logs.prune_failed', [
                    'path' => basename($path),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        if ($deleted > 0) {
            Log::info('logs.pruned', ['count' => $deleted, 'days' => $days]);
        }

        $this->info("Deleted {$deleted} log file(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
