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
        // Best-effort tag materialization (Scout/tag writes + a staged
        // cuisine_primary) stays OUTSIDE the row lock: its I/O must not hold a
        // place lock, and a throw here never blocks the counter writes (the share
        // is already live). It only touches tag tables + stages cuisine_primary on
        // the instance, which the locked rollCounters save below persists.
        try {
            app(TagMaterializer::class)->materialize(
                $place,
                $source->extraction_snapshot_json,
                $source->analysisRun?->overall_confidence !== null ? (float) $source->analysisRun->overall_confidence : null,
            );
        } catch (Throwable $e) {
            report($e);
        }

        // Activation decision + counter read/write under a row lock so two shares
        // publishing to the same place concurrently (two influencers posting the
        // same restaurant) can't both read a stale published-source count and
        // clobber each other's shares_count or activation trigger — the last save
        // would otherwise win with a smaller count and possibly write back a stale
        // status, undoing the other's activation (T-087). PlaceMerger locks both
        // rows for exactly this reason.
        DB::transaction(function () use ($place, $share): void {
            $locked = Place::query()->whereKey($place->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            // Adopt the just-locked, authoritative status: a concurrent publish may
            // already have activated or revived the place. Deciding off the pre-lock
            // snapshot would let this pass save a stale status and undo that.
            $place->status = $locked->status;

            // Only PUBLISHED sources count — a sibling attached-but-not-yet-published
            // in another share's resolve window must not prematurely activate a place.
            // Read under the lock so both concurrent publishers see the full set.
            $sourceCount = $place->sources()->whereNotNull('published_at')->count();

            // A published source on an orphaned tombstone revives it (T-073): the
            // place is evidenced again, so bring it back to the unverified baseline.
            // The activation rule below can lift it further on the same pass — EXCEPT
            // the Google-verified trigger (ADR-086): a place that was taken
            // down/tombstoned (Removed) must re-earn the map through the normal
            // corroboration path, not silently jump back to Active off its cached
            // Google data, so a moderator's removal isn't undone by one re-share.
            $wasRemoved = $place->status === PlaceStatus::Removed;
            if ($wasRemoved && $sourceCount >= 1) {
                $place->status = PlaceStatus::Pending;
            }

            if ($place->status === PlaceStatus::Pending
                && ($sourceCount >= 2 || $share->user_confirmed || (! $wasRemoved && $this->isGoogleVerified($place)))) {
                $place->status = PlaceStatus::Active;
            }

            $this->rollCounters($place, $sourceCount);
        });
    }

    /**
     * Recompute a place after one of its sources was REMOVED (admin reprocess /
     * take-down, T-072) — the mirror of {@see recompute} for the un-publish
     * direction. An orphaned place (no published source, not saved) is tombstoned
     * off every surface; a surviving one just has its counters re-derived from the
     * remaining published sources. Never re-activates (a lost source can't confirm
     * a place) and never overrides a Merged/Hidden row (tombstoneIfOrphaned guards).
     */
    public function recountCounters(Place $place): void
    {
        if ($place->tombstoneIfOrphaned()) {
            return; // now a Removed tombstone — off the map, counters moot
        }

        $this->rollCounters($place, $place->sources()->whereNotNull('published_at')->count());
    }

    /**
     * Persist the denormalized counters (shares_count + avg confidence) from the
     * place's published source set. The single writer for both counters so the
     * publish, un-publish, and restore paths can't drift on how they're derived.
     * Saves the row (including any status change the caller set on the instance).
     */
    public function rollCounters(Place $place, int $publishedCount): void
    {
        $place->shares_count = $publishedCount;
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
