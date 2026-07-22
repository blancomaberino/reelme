<?php

namespace App\Listeners;

use App\Enums\ShareStatus;
use App\Events\ShareStatusChanged;
use App\Notifications\ShareFailed;
use App\Notifications\SharePublished;
use App\Notifications\ShareReviewNeeded;

/**
 * The notification leg of the async pipeline (T-027): a transition *into*
 * `published` / `review` / `failed` notifies the sharer (database + Expo push)
 * so a user who left the app gets deep-linked back. Other transitions
 * (fetching/analyzing, and the intermediate states) are silent — only the
 * outcomes the user cares about push. Notifications are `ShouldQueue`, so the
 * send happens off the pipeline job's thread.
 */
class SendShareStatusNotification
{
    public function handle(ShareStatusChanged $event): void
    {
        $notification = match ($event->to) {
            ShareStatus::Published => new SharePublished($event->share),
            ShareStatus::Review => new ShareReviewNeeded($event->share),
            ShareStatus::Failed => new ShareFailed($event->share),
            default => null,
        };

        if ($notification === null) {
            return;
        }

        // Eager-load the sharer (and, only for a published share, its place) so the
        // queued notification — PHP-serialized whole into its job — carries them to
        // the worker, where url()/placeName() read them without re-querying. Only
        // SharePublished touches the place; review/failed would just pay an empty
        // publishedPlaceSource query.
        $event->share->loadMissing(
            $event->to === ShareStatus::Published ? ['user', 'publishedPlaceSource.place'] : ['user'],
        );

        $event->share->user?->notify($notification);
    }
}
