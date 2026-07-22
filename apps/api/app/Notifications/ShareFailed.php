<?php

namespace App\Notifications;

/**
 * A share failed in the pipeline (T-027). Deep-links to the status screen, which
 * surfaces the failure reason and any retry/manual-entry action.
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

    protected function title(): string
    {
        return 'No pudimos procesar tu enlace';
    }

    protected function body(): string
    {
        return 'Tocá para ver qué pasó y volver a intentar.';
    }
}
