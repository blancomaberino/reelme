<?php

namespace App\Services\Moderation;

use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Places\PlacePublisher;
use Illuminate\Support\Facades\DB;

/**
 * Admin moderation (T-072): take a single share down — remove one user's
 * contribution without touching what others published. Un-publishing the share
 * (status → Rejected) drops its feed/profile card, and nulling its sources'
 * `published_at` then recounting drops the map pin ONLY when this share was the
 * pin's last published contributor (a place also shared by someone else survives).
 * That "full take-down when solely responsible" is the honest share-level reach.
 */
class ShareModerator
{
    public function __construct(private readonly PlacePublisher $publisher) {}

    public function takeDown(Share $share): void
    {
        DB::transaction(function () use ($share): void {
            $sources = PlaceSource::query()->where('share_id', $share->id)->get();
            $places = Place::query()->whereIn('id', $sources->pluck('place_id'))->get();

            foreach ($sources as $source) {
                $source->published_at = null;
                $source->save();
            }

            $share->published_place_source_id = null;
            $share->save();
            $share->forceResetStatus(ShareStatus::Rejected, 'admin_removed');

            // Tombstone a now-orphaned pin, or re-derive counters for one that
            // other shares keep alive.
            foreach ($places as $place) {
                $this->publisher->recountCounters($place);
            }
        });
    }
}
