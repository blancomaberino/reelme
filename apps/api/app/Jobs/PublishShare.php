<?php

namespace App\Jobs;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\PlaceSource;
use App\Models\Share;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Terminal pipeline stage (04 §7): freezes the publish-time snapshot, flips the
 * share to `published`, activates the place per the second-source/user-confirm
 * rule, and rolls the place counters. Idempotent across Horizon redelivery — the
 * one-time writes are gated on winning the optimistic analyzing→published guard.
 */
class PublishShare extends PipelineStubJob
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public int $timeout = 30;

    protected function stage(): string
    {
        return 'publish';
    }

    protected function queueName(): string
    {
        return 'analysis';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Analyzing;
    }

    protected function run(Share $share): void
    {
        // Defensive idempotency (PipelineStubJob already guards on Analyzing).
        if ($share->status === ShareStatus::Published) {
            return;
        }

        $source = PlaceSource::query()->where('share_id', $share->id)->first();
        if ($source === null) {
            return; // resolve parked the share to review — nothing to publish yet
        }

        // Freeze the as-published snapshot: the corrected place payload when the
        // user amended the extraction in review, else the resolver's original.
        if (is_array($share->corrected_extraction_json)) {
            $place = $share->corrected_extraction_json['place'] ?? null;
            if (is_array($place)) {
                $source->extraction_snapshot_json = $place;
                $source->save();
            }
        }

        // Only the worker that actually wins the transition performs the one-time
        // counter/activation writes — a redelivered job no-ops here.
        if (! $share->transitionTo(ShareStatus::Published)) {
            return;
        }

        $share->published_place_source_id = $source->id;
        $share->save();

        $this->activateAndCount($source, $share);
    }

    /**
     * A single unverified source keeps the place `pending`; a second independent
     * source or an explicit user confirmation promotes it to `active` (04 §6.4).
     * Never downgrades. Counters are recomputed from the source set, not summed,
     * so a re-run would be self-correcting anyway.
     */
    private function activateAndCount(PlaceSource $source, Share $share): void
    {
        $place = $source->place;
        if ($place === null) {
            return;
        }

        $sourceCount = $place->sources()->count();

        if ($place->status === PlaceStatus::Pending && ($sourceCount >= 2 || $share->user_confirmed)) {
            $place->status = PlaceStatus::Active;
        }

        $place->shares_count = $sourceCount;
        $place->avg_extraction_confidence = $this->avgConfidence($place->id);
        $place->save();
    }

    /** Rolling average of the non-null model confidences across the place's sources. */
    private function avgConfidence(int $placeId): ?float
    {
        $avg = DB::table('place_sources')
            ->join('analysis_runs', 'analysis_runs.id', '=', 'place_sources.analysis_run_id')
            ->where('place_sources.place_id', $placeId)
            ->whereNotNull('analysis_runs.overall_confidence')
            ->avg('analysis_runs.overall_confidence');

        return $avg !== null ? (float) $avg : null;
    }

    public function failed(Throwable $e): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || ! $share->canTransitionTo(ShareStatus::Failed)) {
            return;
        }

        $share->transitionTo(ShareStatus::Failed, 'publish_error');
    }
}
