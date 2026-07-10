<?php

namespace App\Adapters\Data;

/**
 * A media item advertised by a post's metadata (not yet downloaded).
 * `url` is null when the platform only exposes bytes via an authenticated
 * fetcher (e.g. yt-dlp).
 */
final readonly class MediaDescriptor
{
    public function __construct(
        public string $type,          // 'video' | 'image' | 'audio'
        public ?string $url = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $duration = null, // seconds
    ) {}
}
