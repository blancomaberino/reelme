<?php

namespace App\Jobs;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Places\TagMaterializer;
use Illuminate\Support\Collection;
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
        return 'publish';
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

        /** @var Collection<int, PlaceSource> $sources */
        $sources = PlaceSource::query()->where('share_id', $share->id)->get();
        if ($sources->isEmpty()) {
            return; // resolve parked the share to review — nothing to publish yet
        }

        // Freeze the as-published snapshot for a single-place share the user
        // amended in review; a multi-place share keeps each source's resolver
        // snapshot (per-place review corrections are applied at resolve time).
        if ($sources->count() === 1 && is_array($share->corrected_extraction_json)) {
            $corrected = $this->correctedPlace($share->corrected_extraction_json);
            if ($corrected !== null) {
                $sources->first()->extraction_snapshot_json = $corrected;
                $sources->first()->save();
            }
        }

        // Only the worker that actually wins the transition performs the one-time
        // counter/activation writes — a redelivered job no-ops here.
        if (! $share->transitionTo(ShareStatus::Published)) {
            return;
        }

        $now = now();
        foreach ($sources as $source) {
            $source->published_at = $now;
            $source->save();
            $this->activateAndCount($source, $share);
        }

        // The primary source (or first) drives the map "jump to pin" affordance.
        $primary = $sources->firstWhere('is_primary', true) ?? $sources->first();
        $share->published_place_source_id = $primary->id;
        $share->save();
    }

    /**
     * The corrected place payload for a single-place share (places[] first entry,
     * or the pre-v6 singular place).
     *
     * @param  array<string, mixed>  $corrected
     * @return array<string, mixed>|null
     */
    private function correctedPlace(array $corrected): ?array
    {
        if (is_array($corrected['places'][0] ?? null)) {
            return $corrected['places'][0];
        }

        return is_array($corrected['place'] ?? null) ? $corrected['place'] : null;
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

        // Materialize discovery tags from the as-published snapshot (T-031) —
        // before save() so cuisine_primary backfill and the Scout re-index (the
        // searchable document embeds tag slugs) ride the same write. NON-FATAL:
        // the share is already Published (one-shot transition), so a throw here
        // would permanently skip the counter/activation writes below on retry.
        // Tags are recoverable via reelmap:tags:backfill; counters are not.
        try {
            app(TagMaterializer::class)->materialize(
                $place,
                $source->extraction_snapshot_json,
                $source->analysisRun?->overall_confidence !== null
                    ? (float) $source->analysisRun->overall_confidence
                    : null,
            );
        } catch (Throwable $e) {
            report($e);
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
