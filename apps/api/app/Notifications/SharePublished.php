<?php

namespace App\Notifications;

/**
 * A share auto-published (T-027). Deep-links to the new place's detail screen.
 * URL uses the mobile router's actual path `/place/{slug}` (singular, by slug),
 * not the spec table's `/places/:id` — recorded as a deviation in the T-027 log.
 */
class SharePublished extends ShareNotification
{
    protected function type(): string
    {
        return 'share.published';
    }

    protected function url(): string
    {
        $slug = $this->share->publishedPlaceSource?->place?->slug;

        // A published share should always have a place; fall back to its status
        // screen rather than emit a broken deep-link if the place is missing.
        return is_string($slug) && $slug !== ''
            ? '/place/'.$slug
            : '/shares/'.$this->share->id.'/status';
    }

    protected function title(): string
    {
        return '¡Lugar añadido!';
    }

    protected function body(): string
    {
        $name = $this->placeName();

        return $name !== null
            ? $name.' ya está en tu mapa.'
            : 'Tu lugar ya está en el mapa.';
    }
}
