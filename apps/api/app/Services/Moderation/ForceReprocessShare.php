<?php

namespace App\Services\Moderation;

use App\Jobs\Pipeline;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Places\PlacePublisher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Admin moderation (T-072): re-run a share's extraction pipeline from scratch,
 * even when it already published. The normal state machine forbids this (a
 * `published` share is terminal and every stage no-ops on a status mismatch),
 * which is exactly why a moderator needs it — a reel processed under an old
 * prompt version can carry a wrong pin that only a fresh extraction can fix.
 *
 * The re-run is data-safe: deleting the prior `place_sources` lets ResolvePlace
 * re-resolve (it no-ops while sources exist), the `forceExtract` flag makes
 * ExtractPlaceData re-invoke the LLM instead of reusing the old succeeded run,
 * and PublishShare's counters are recomputed (not summed) so nothing double-counts.
 */
class ForceReprocessShare
{
    public function __construct(private readonly PlacePublisher $publisher) {}

    /**
     * @param  string  $fromStage  Pipeline stage to resume from (default `extract`
     *                             re-runs the LLM on already-fetched media; `fetch`
     *                             re-downloads too). Must be a key of Pipeline::STAGES.
     */
    public function run(Share $share, string $fromStage = 'extract'): void
    {
        DB::transaction(function () use ($share, $fromStage): void {
            $places = Place::query()
                ->whereIn('id', PlaceSource::query()->where('share_id', $share->id)->pluck('place_id'))
                ->get();

            PlaceSource::query()->where('share_id', $share->id)->delete();

            // The just-orphaned old pins fall off the map (or, if still sourced by
            // another share, have their counters re-derived) before the re-run.
            foreach ($places as $place) {
                $this->publisher->recountCounters($place);
            }

            $share->published_place_source_id = null;
            $share->save();

            // Bypass the terminal-state guard: reset to the resume stage's entry
            // status so PipelineStubJob's guard lets the re-dispatched chain fire.
            $share->forceResetStatus(Pipeline::entryStatus($fromStage));
        });

        Bus::chain(Pipeline::chain($share->id, $fromStage, forceExtract: true))->dispatch();
    }
}
