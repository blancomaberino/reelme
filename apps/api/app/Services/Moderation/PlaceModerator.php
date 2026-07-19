<?php

namespace App\Services\Moderation;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Services\Places\PlacePublisher;

/**
 * Admin moderation (T-072): take a map pin down and put it back. A single column
 * change ({@see PlaceStatus::Removed}) pulls the place off EVERY public surface at
 * once — the global map/browse/search filter on `publiclyVisible` (matchable
 * status), and the feed/profile cards additionally require the published place to
 * be `publiclyVisible`, so a Removed place disappears from both. Soft & reversible:
 * the underlying sources are untouched, so `restore()` re-derives the pin's status.
 */
class PlaceModerator
{
    public function __construct(private readonly PlacePublisher $publisher) {}

    /**
     * Take the given places off the map. Only a live (matchable) place is affected
     * — a Merged tombstone or an already-Removed row is left as is.
     *
     * @param  iterable<Place>  $places
     */
    public function takeDown(iterable $places): void
    {
        foreach ($places as $place) {
            if (! in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true)) {
                continue;
            }
            $place->status = PlaceStatus::Removed;
            $place->save();
        }
    }

    /**
     * Reverse a take-down: bring a Removed place back to its natural status derived
     * from its still-published sources (Active once ≥2 sources vouch for it, else
     * Pending). Only reverses a take-down — never un-hides an admin Hidden row or
     * un-merges a Merged one, and never resurrects a genuine ORPHAN: `Removed` is
     * shared with the auto-tombstone ({@see Place::tombstoneIfOrphaned}, T-071/073),
     * so a Removed place with zero published sources is a provenance-less ghost, not
     * an admin take-down — restoring it would put a sourceless pin back on the map.
     *
     * @param  iterable<Place>  $places
     */
    public function restore(iterable $places): void
    {
        foreach ($places as $place) {
            if ($place->status !== PlaceStatus::Removed) {
                continue;
            }
            $publishedSources = $place->sources()->whereNotNull('published_at')->count();
            if ($publishedSources < 1) {
                continue; // orphan tombstone, not a take-down — nothing to vouch for it
            }
            $place->status = $publishedSources >= 2 ? PlaceStatus::Active : PlaceStatus::Pending;
            // rollCounters saves the row (status + shares_count + refreshed avg confidence).
            $this->publisher->rollCounters($place, $publishedSources);
        }
    }
}
