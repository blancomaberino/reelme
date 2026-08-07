<?php

namespace App\Services\Moderation;

use App\Enums\TakedownStatus;
use App\Models\MediaAsset;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\TakedownRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Execute a takedown (T-049, IR-2 / R-07 / ADR-010).
 *
 * The shape here follows FR-30: **the place survives, its source does not.** A
 * rightsholder is objecting to their footage being used, not to the existence
 * of a restaurant — and other people may have contributed the same place
 * independently. Deleting the pin would take their contribution with it and
 * answer a copyright complaint by destroying unrelated data.
 *
 * So: unpublish every share citing the post, drop the `place_sources` rows that
 * cite it, delete the stored media, and leave the `source_posts` row itself in
 * place. That last part matters — other analytics reference it, and a deleted
 * row would cascade its media away silently and lose the record that a takedown
 * ever happened here.
 */
class ProcessTakedown
{
    public function __construct(private readonly ShareModerator $shares) {}

    /**
     * @return array{shares: int, place_sources: int, media: int, places_kept: list<int>}
     */
    public function execute(TakedownRequest $request, User $admin): array
    {
        $post = $request->sourcePost;

        if ($post === null) {
            // Nothing matched to act on yet. Recorded rather than silently
            // skipped: a notice that cannot be actioned still needs an answer.
            $outcome = ['shares' => 0, 'place_sources' => 0, 'media' => 0, 'places_kept' => []];
            $this->close($request, $admin, $outcome, 'unmatched');

            return $outcome;
        }

        // Read the objects BEFORE the rows go: once `media_assets` is deleted
        // there is no handle left on the files at all.
        $objects = MediaAsset::query()
            ->where('source_post_id', $post->id)
            ->get(['id', 'disk', 'storage_path']);

        $placesKept = [];
        $sourcesRemoved = 0;
        $sharesTaken = 0;

        DB::transaction(function () use ($post, &$placesKept, &$sourcesRemoved, &$sharesTaken): void {
            // Every share citing this post loses its publication. Routed through
            // ShareModerator so the pin arithmetic (recount, tombstone-if-orphaned)
            // is the same one the admin take-down uses — a second copy would
            // diverge and leave ghost pins.
            $shares = Share::query()->where('source_post_id', $post->id)->get();

            foreach ($shares as $share) {
                $this->shares->takeDown($share);
                $sharesTaken++;
            }

            $sources = PlaceSource::query()->where('source_post_id', $post->id)->get();
            $placesKept = $sources->pluck('place_id')->unique()->values()->all();
            $sourcesRemoved = $sources->count();

            PlaceSource::query()->where('source_post_id', $post->id)->delete();

            // The row STAYS. Deleting it would cascade its media away outside
            // our control and erase the only record that this post was ever
            // here — which is exactly what a counter-notice would ask about.
            // The caption and the cached payload are what actually reproduce
            // the rightsholder's material, so those go.
            $post->forceFill(['caption' => null, 'oembed_json' => null, 'transcript_json' => null])->save();

            MediaAsset::query()->where('source_post_id', $post->id)->delete();
        });

        $this->deleteObjects($objects);

        $outcome = [
            'shares' => $sharesTaken,
            'place_sources' => $sourcesRemoved,
            'media' => $objects->count(),
            'places_kept' => $placesKept,
        ];

        $this->close($request, $admin, $outcome, 'actioned');

        return $outcome;
    }

    /**
     * @param  Collection<int, MediaAsset>  $objects
     */
    private function deleteObjects($objects): void
    {
        foreach ($objects as $asset) {
            try {
                Storage::disk($asset->disk)->delete($asset->storage_path);
            } catch (\Throwable $e) {
                // A takedown is a legal obligation with a clock on it — a
                // blinking bucket must not abort the rest of it. The row is
                // already gone, so the retention sweep is what catches the file.
                Log::warning('moderation.takedown.media_delete_failed', [
                    'media_asset_id' => $asset->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function close(TakedownRequest $request, User $admin, array $outcome, string $result): void
    {
        $request->forceFill([
            'status' => TakedownStatus::Actioned,
            'actioned_by_user_id' => $admin->id,
            'actioned_at' => now(),
            'outcome_json' => $outcome,
        ])->save();

        Log::info('moderation.takedown.processed', [
            'takedown_request_id' => $request->id,
            'result' => $result,
            'admin_id' => $admin->id,
            'source_post_id' => $request->source_post_id,
        ] + $outcome);
    }

    /** Match a bare URL to a source post, so ops can log first and match later. */
    public function matchByUrl(string $url): ?SourcePost
    {
        return SourcePost::query()->where('url', trim($url))->first();
    }
}
