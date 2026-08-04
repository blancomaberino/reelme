<?php

namespace App\Notifications;

/**
 * A share failed in the pipeline (T-027). Deep-links to the status screen, which
 * surfaces the failure reason and any retry/manual-entry action. Copy comes from
 * `notifications.share.failed.*` in the recipient's language.
 */
class ShareFailed extends ShareNotification
{
    protected function type(): string
    {
        return 'share.failed';
    }

    protected function url(): string
    {
        return '/shares/'.$this->share->id.'/status';
    }
}
