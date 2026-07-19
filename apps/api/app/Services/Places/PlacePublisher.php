<?php

namespace App\Services\Places;

use App\Enums\PlaceStatus;
use App\Jobs\PublishShare;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recompute a place's denormalized state from its PUBLISHED sources — the single
 * home for the "a source went live" bookkeeping (04 §6.4), shared by the normal
 * publish path ({@see PublishShare}) and the pending-venue resolve path
 * ({@see ResolvePendingPlace}) so the two can never drift (e.g. one forgetting to
 * re-average confidence). Activation never downgrades; counters are recomputed
 * from the source set (not summed), so a re-run is self-correcting.
 */
class PlacePublisher
{
    /**
     * Activate the place per the second-source/user-confirm rule, roll its
     * counters (shares_count + avg confidence), and materialize discovery tags
     * from the just-published source. Tag materialization is best-effort — a
     * throw there never blocks the counter writes (the share is already live).
     */
    public function recompute(Place $place, Share $share, PlaceSource $source): void
    {
        // Only PUBLISHED sources count — a sibling attached-but-not-yet-published
        // in another share's resolve window must not prematurely activate a place.
        $sourceCount = $place->sources()->whereNotNull('published_at')->count();

        // A published source on an orphaned tombstone revives it (T-073): the
        // place is evidenced again, so bring it back to the unverified baseline;
        // the activation rule below can lift it further on the same pass.
        if ($place->status === PlaceStatus::Removed && $sourceCount >= 1) {
            $place->status = PlaceStatus::Pending;
        }

        if ($place->status === PlaceStatus::Pending
            && ($sourceCount >= 2 || $share->user_confirmed || $this->isGoogleVerified($place))) {
            $place->status = PlaceStatus::Active;
        }

        try {
            app(TagMaterializer::class)->materialize(
                $place,
                $source->extraction_snapshot_json,
                $source->analysisRun?->overall_confidence !== null ? (float) $source->analysisRun->overall_confidence : null,
            );
        } catch (Throwable $e) {
            report($e);
        }

        $place->shares_count = $sourceCount;
        $place->avg_extraction_confidence = $this->avgConfidence($place->id);
        $place->save();
    }

    /**
     * A place that resolved to a real Google Places establishment WITH at least
     * one review is third-party-verified — a stronger corroboration signal than a
     * second influencer share, so it activates on the first source (ADR-086). The
     * resolver persists `google_rating_count` at resolve time, before this runs.
     * A bare google_place_id with no reviews (a thin/address-only match) stays
     * pending until a second source or a human confirms it.
     */
    private function isGoogleVerified(Place $place): bool
    {
        return $place->google_place_id !== null && (int) ($place->google_rating_count ?? 0) >= 1;
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
}
