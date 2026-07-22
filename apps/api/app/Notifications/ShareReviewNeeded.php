<?php

namespace App\Notifications;

/**
 * A share landed in `review` — the pipeline is uncertain and wants a quick
 * confirm (T-027, T-098). Deep-links to the review screen.
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

    protected function title(): string
    {
        return 'Revisá tu lugar';
    }

    protected function body(): string
    {
        return 'Confirmá algunos datos para terminar de agregarlo.';
    }
}
