<?php

namespace App\Console\Commands;

use App\Enums\ShareStatus;
use App\Models\Share;
use App\Services\Places\PublishBestGuess;
use Illuminate\Console\Command;
use Throwable;

/**
 * Confirm-before-publish (T-098): a sharer who leaves an uncertain share's confirm
 * step unanswered (shared and closed the app) must not have their place stuck in
 * limbo. This sweep publishes the best guess for review shares that have sat idle
 * past the grace window — the same {@see PublishBestGuess} path the in-app "Publish
 * as-is" button uses, so the place goes live + lands in the admin queue rather than
 * dead-ending on the sharer.
 *
 * Only best-guessable reasons are swept (low_confidence / ambiguous_place);
 * geocode_failed / no_place_extracted have no location to publish and are left for
 * an admin (or the sharer's pin) to locate. Scheduled every 5 minutes.
 */
class PublishAbandonedReviews extends Command
{
    /** Minutes a review may sit idle before its best guess is auto-published. */
    private const DEFAULT_MINUTES = 15;

    protected $signature = 'reelmap:reviews:publish-abandoned {--minutes= : Idle minutes before auto-publish (default: 15)}';

    protected $description = 'Publish the best guess for uncertain shares left idle in review past the grace window';

    public function handle(PublishBestGuess $bestGuess): int
    {
        $minutes = $this->option('minutes') !== null ? (int) $this->option('minutes') : self::DEFAULT_MINUTES;
        $cutoff = now()->subMinutes(max(1, $minutes));
        $published = 0;
        $skipped = 0;

        Share::query()
            ->where('status', ShareStatus::Review)
            // best-guessable reasons only — geocode_failed/no_place_extracted can't
            // be placed without human input, so they stay for admin/pin location.
            // (canPublish() re-checks per row, incl. the multi-place ambiguous case.)
            ->whereIn('review_reason', PublishBestGuess::PLACEABLE_REASONS)
            // idle past the grace window (updated_at moves on any edit, so an
            // actively-corrected share isn't swept out from under the sharer).
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($shares) use ($bestGuess, &$published, &$skipped) {
                foreach ($shares as $share) {
                    // Per-row isolation: one bad share must never abort the sweep.
                    try {
                        if ($bestGuess->publish($share)) {
                            $published++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                        $skipped++;
                    }
                }
            });

        $this->info("Auto-published {$published} abandoned review(s); skipped {$skipped}.");

        return self::SUCCESS;
    }
}
