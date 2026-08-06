<?php

namespace App\Notifications;

/**
 * A share landed in `review` — the pipeline is uncertain and wants a quick
 * confirm (T-027, T-098). Deep-links to the review screen. Copy comes from
 * `notifications.share.review_needed.*` in the recipient's language.
 */
class ShareReviewNeeded extends ShareNotification
{
    protected function type(): string
    {
        return 'share.review_needed';
    }

    protected function url(): string
    {
        return '/shares/'.$this->share->id.'/review';
    }
}
