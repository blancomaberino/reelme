<?php

namespace App\Services\Moderation;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceListItem;

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
     * orphan (that comes back via a re-share) or un-merges a Merged row. A Hidden
     * place that has lost all provenance (no published source and not saved to any
     * list — e.g. after its only share was reprocessed away) is NOT restored: that
     * would put a sourceless ghost pin back on the public map.
     *
     * @param  iterable<Place>  $places
     */
    public function restore(iterable $places): void
    {
        foreach ($places as $place) {
            if ($place->status !== PlaceStatus::Hidden || ! self::hasProvenance($place)) {
                continue;
            }
            $place->status = PlaceStatus::Pending;
            $place->save();
        }
    }

    /** A place is worth showing again only if a published source or a saver vouches for it. */
    public static function hasProvenance(Place $place): bool
    {
        return $place->sources()->whereNotNull('published_at')->exists()
            || PlaceListItem::query()->where('place_id', $place->id)->exists();
    }
}
