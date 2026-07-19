<?php

namespace App\Services\Moderation;

use App\Enums\PlaceStatus;
use App\Models\Place;

/**
 * Admin moderation (T-072): hide a map pin and restore it — the BULK counterpart
 * of the per-record Hide/Restore on the place detail page (T-035, ViewPlace), and
 * it uses the SAME {@see PlaceStatus::Hidden} status so the two surfaces stay one
 * concept. Hidden fails `publiclyVisible` (matchable = pending|active), so the
 * place drops off the global map/browse/search AND the feed/profile cards (which
 * require the published place to be publiclyVisible) in a single column change.
 * Soft & reversible; sources are untouched. `Removed` is deliberately NOT used
 * here — it belongs to the auto-orphan tombstone path (ShareModerator /
 * ForceReprocessShare via {@see Place::tombstoneIfOrphaned}).
 */
class PlaceModerator
{
    /**
     * Hide the given places (admin take-down). Only a live (matchable) place is
     * affected — a Merged/Removed tombstone or an already-Hidden row is left as is.
     *
     * @param  iterable<Place>  $places
     */
    public function takeDown(iterable $places): void
    {
        foreach ($places as $place) {
            if (! in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true)) {
                continue;
            }
            $place->status = PlaceStatus::Hidden;
            $place->save();
        }
    }

    /**
     * Reverse a hide: bring a Hidden place back to the review queue (Pending),
     * matching the per-record Restore. Only un-hides — never revives a Removed
     * orphan (that comes back via a re-share) or un-merges a Merged row.
     *
     * @param  iterable<Place>  $places
     */
    public function restore(iterable $places): void
    {
        foreach ($places as $place) {
            if ($place->status !== PlaceStatus::Hidden) {
                continue;
            }
            $place->status = PlaceStatus::Pending;
            $place->save();
        }
    }
}
