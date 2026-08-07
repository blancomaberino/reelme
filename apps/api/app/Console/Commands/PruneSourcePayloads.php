<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drop cached provider payloads once they stop being useful (T-050, NFR-11).
 *
 * `source_posts.oembed_json` is the raw response a platform gave us at fetch
 * time. Everything the product uses was parsed out of it into our own columns
 * within seconds; what remains is a debugging aid — and one that can carry
 * author names, avatars and profile URLs we never asked for and do not need.
 *
 * So it is kept for a window long enough to diagnose a bad extraction, then
 * dropped. The row itself stays: it is the identity of the post, and places on
 * the map cite it.
 *
 * Nulled, not deleted. The transcript beside it (`transcript_json`) is derived
 * work we produced and is deliberately untouched.
 */
class PruneSourcePayloads extends Command
{
    protected $signature = 'reelmap:sources:prune-payloads {--dry-run : Report the count without writing}';

    protected $description = 'Null raw oembed payloads older than the retention window (T-050, NFR-11)';

    public function handle(): int
    {
        $days = (int) config('media.retention.oembed_days');
        $cutoff = now()->subDays($days);

        $query = DB::table('source_posts')
            ->whereNotNull('oembed_json')
            // fetched_at, not created_at: the payload's age is the age of the
            // FETCH. A post row created early and re-fetched last week holds a
            // week-old payload, not a months-old one.
            ->where(fn ($q) => $q->where('fetched_at', '<', $cutoff)
                ->orWhere(fn ($inner) => $inner->whereNull('fetched_at')->where('created_at', '<', $cutoff)));

        if ($this->option('dry-run')) {
            $this->info("Would clear {$query->count()} cached payload(s).");

            return self::SUCCESS;
        }

        $cleared = 0;

        // Chunked: this table grows with every share ever made, and one
        // unbounded UPDATE would hold a lock across all of it while the ingest
        // pipeline is trying to write new rows.
        $query->select('id')->orderBy('id')->chunkById(500, function ($rows) use (&$cleared): void {
            $cleared += DB::table('source_posts')
                ->whereIn('id', collect($rows)->pluck('id'))
                ->update(['oembed_json' => null]);
        });

        if ($cleared > 0) {
            Log::info('sources.payloads.pruned', ['count' => $cleared]);
        }

        $this->info("Cleared {$cleared} cached payload(s).");

        return self::SUCCESS;
    }
}
