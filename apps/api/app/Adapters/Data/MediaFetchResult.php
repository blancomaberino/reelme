<?php

namespace App\Adapters\Data;

/**
 * The set of concrete media a fetchMedia() call resolved for a post. Stands
 * alone from metadata because media and metadata may come from different
 * adapters in a chain (04 §2).
 */
final readonly class MediaFetchResult
{
    /**
     * @param  array<int, FetchedMedia>  $media
     */
    public function __construct(
        public array $media = [],
    ) {}
}
