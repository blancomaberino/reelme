<?php

namespace App\Services\Media\Data;

/**
 * ffprobe metadata for a media file: duration, video dimensions, and whether an
 * audio stream is present (so PrepareMedia can skip audio extraction on silent
 * clips and DownloadMedia can enforce the duration cap).
 */
final readonly class MediaProbe
{
    public function __construct(
        public int $durationMs,
        public ?int $width,
        public ?int $height,
        public bool $hasAudio,
    ) {}
}
