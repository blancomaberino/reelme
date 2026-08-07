<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sweep finished GDPR export archives off the disk (T-050).
 *
 * An export is the densest single file of personal data the system produces.
 * Leaving them to accumulate would quietly rebuild, in one folder, the exact
 * concentration of personal data the export feature exists to hand OUT — and
 * every one of them outlives the signed link that was the point of making it.
 *
 * Kept a little longer than the link TTL so support can re-send a lapsed link
 * without regenerating the archive, then removed.
 */
class PruneDataExports extends Command
{
    protected $signature = 'reelmap:gdpr:prune-exports';

    protected $description = 'Delete GDPR export archives past their retention window (T-050)';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('media.disk'));
        $cutoff = now()->subDays((int) config('gdpr.export_retention_days'));

        $deleted = 0;

        foreach ($disk->allFiles('exports') as $path) {
            // Timestamped by the filesystem rather than a DB row on purpose:
            // the archive is the artefact, and a row tracking it would be a
            // second thing to keep in step (and to purge).
            if ($disk->lastModified($path) >= $cutoff->getTimestamp()) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        if ($deleted > 0) {
            Log::info('gdpr.exports.pruned', ['count' => $deleted]);
        }

        $this->info("Deleted {$deleted} expired export archive(s).");

        return self::SUCCESS;
    }
}
