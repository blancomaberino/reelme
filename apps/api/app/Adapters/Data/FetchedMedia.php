<?php

namespace App\Adapters\Data;

use App\Enums\MediaKind;

/**
 * A concrete media item ready for the pipeline to ingest. HTTP adapters return a
 * short-lived `url`; yt-dlp adapters return a `localPath`. Exactly one is set.
 */
final readonly class FetchedMedia
{
    public function __construct(
        public MediaKind $kind,
        public ?string $url = null,
        public ?string $localPath = null,
        public ?string $mime = null,
    ) {}
}
