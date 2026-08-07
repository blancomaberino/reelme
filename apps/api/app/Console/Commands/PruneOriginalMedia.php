<?php

namespace App\Console\Commands;

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Analyze-then-delete (T-050, ADR-010 / R-07).
 *
 * We download somebody else's video to look at it, and then we should not still
 * have it. That is the whole policy: an original is an input to the analysis,
 * not an asset — and a growing archive of other people's footage is a copyright
 * exposure that compounds with every share whether or not anyone ever watches
 * one.
 *
 * Kept: keyframes, thumbnails, transcripts, the extraction JSON. Those are our
 * own derived work and they are what the product actually renders.
 * Deleted: the source video and the WAV extracted from it.
 *
 * Two clocks, because "finished" and "stuck" need different answers:
 *  - a share whose chain has ENDED (published/failed/rejected) drops its
 *    original `retention.original_hours` after the asset was created;
 *  - a share still IN FLIGHT keeps it, so a retry has something to re-read —
 *    but only until `in_flight_ceiling_hours`, after which it goes anyway.
 *    Re-analysis already requires a re-fetch (ADR-010), so the cost of being
 *    wrong is a re-download; the cost of no ceiling is a wedged share pinning
 *    a video forever.
 */
class PruneOriginalMedia extends Command
{
    protected $signature = 'reelmap:media:prune-originals {--dry-run : List what would go without deleting anything}';

    protected $description = 'Delete analysed original videos/audio past their retention window (T-050, ADR-010)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $retention = (int) config('media.retention.original_hours');
        $ceiling = (int) config('media.retention.in_flight_ceiling_hours');

        $cutoff = now()->subHours($retention);
        $hardCutoff = now()->subHours($ceiling);

        $deleted = 0;
        $kept = 0;

        $this->eligible($cutoff, $hardCutoff)->chunkById(200, function ($assets) use (&$deleted, &$kept, $dryRun): void {
            foreach ($assets as $asset) {
                if ($dryRun) {
                    $this->line("would delete: [{$asset->kind}] {$asset->storage_path}");
                    $deleted++;

                    continue;
                }

                if ($this->deleteAsset($asset)) {
                    $deleted++;
                } else {
                    $kept++;
                }
            }
        }, 'media_assets.id', 'id');

        $this->info($dryRun
            ? "Would delete {$deleted} original asset(s)."
            : "Deleted {$deleted} original asset(s); {$kept} retained for retry.");

        return self::SUCCESS;
    }

    /**
     * Originals whose share chain says they are done with.
     *
     * Joined through source_posts → shares rather than filtered on age alone:
     * age is not the question, "has anything still got a use for this" is.
     *
     * @return Builder
     */
    private function eligible(Carbon $cutoff, Carbon $hardCutoff)
    {
        $finished = [ShareStatus::Published->value, ShareStatus::Failed->value, ShareStatus::Rejected->value];

        return DB::table('media_assets')
            ->select('media_assets.id', 'media_assets.kind', 'media_assets.disk', 'media_assets.storage_path', 'media_assets.sha256')
            // Originals only. A keyframe is derived work we keep; deleting one
            // would blank a place card that is live on the map.
            ->whereIn('media_assets.kind', [
                MediaKind::Video->value,
                MediaKind::Audio->value,
                MediaKind::ScreenRecording->value,
            ])
            ->where(function ($q) use ($cutoff, $hardCutoff, $finished) {
                // Finished chain, past the normal window.
                $q->where(function ($inner) use ($cutoff, $finished) {
                    $inner->where('media_assets.created_at', '<', $cutoff)
                        ->whereExists(fn ($e) => $e->from('shares')
                            ->whereColumn('shares.source_post_id', 'media_assets.source_post_id')
                            ->whereIn('shares.status', $finished))
                        // Every share on this post must be finished — one still
                        // running is a use for the file.
                        ->whereNotExists(fn ($e) => $e->from('shares')
                            ->whereColumn('shares.source_post_id', 'media_assets.source_post_id')
                            ->whereNotIn('shares.status', $finished));
                })
                    // Or: past the hard ceiling regardless of what the chain says.
                    ->orWhere('media_assets.created_at', '<', $hardCutoff)
                    // Or: orphaned — no share references the post at all, so no
                    // status will ever move and nothing will ever read it.
                    ->orWhere(fn ($inner) => $inner
                        ->where('media_assets.created_at', '<', $cutoff)
                        ->whereNotExists(fn ($e) => $e->from('shares')
                            ->whereColumn('shares.source_post_id', 'media_assets.source_post_id')));
            });
    }

    /**
     * Object first, then the row.
     *
     * That order is the whole reason this is idempotent: if the bucket call
     * fails the row survives and the next run retries it. Deleting the row
     * first would lose the only handle we have on the file and leak it forever.
     */
    private function deleteAsset(object $asset): bool
    {
        // The same bytes can back several source_posts (a repost resolves to the
        // same sha256). Only remove the object when nothing else points at it.
        $shared = DB::table('media_assets')
            ->where('sha256', $asset->sha256)
            ->where('id', '!=', $asset->id)
            ->exists();

        try {
            if (! $shared) {
                Storage::disk($asset->disk)->delete($asset->storage_path);
            }
        } catch (\Throwable $e) {
            Log::warning('media.prune.object_delete_failed', [
                'media_asset_id' => $asset->id,
                'path' => $asset->storage_path,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        DB::table('media_assets')->where('id', $asset->id)->delete();

        Log::info('media.prune.deleted', [
            'media_asset_id' => $asset->id,
            'kind' => $asset->kind,
            'shared_object_kept' => $shared,
        ]);

        return true;
    }
}
