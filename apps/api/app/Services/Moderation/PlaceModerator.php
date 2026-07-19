<?php

namespace App\Services\Moderation;

use App\Enums\PlaceStatus;
use App\Models\Place;

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
     * un-merges a Merged one.
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
            $place->status = $publishedSources >= 2 ? PlaceStatus::Active : PlaceStatus::Pending;
            $place->save();
        }
    }
}
