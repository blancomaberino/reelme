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

        // The sharer owns the share; loadMissing keeps the published-place lookup
        // (used for the deep-link + copy) from N+1'ing when many fire at once.
        $event->share->loadMissing('user', 'publishedPlaceSource.place');

        $event->share->user?->notify($notification);
    }
}
