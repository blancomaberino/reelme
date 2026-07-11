<?php

namespace App\Services\Media\Data;

/**
 * The derivatives PrepareMedia produces from an original: an optional 16 kHz
 * mono WAV (null when the source is silent), the chronological keyframes, and a
 * poster thumbnail — all local temp paths, uploaded to the media disk by the job.
 */
final readonly class ProcessedMedia
{
    /**
     * @param  list<ExtractedFrame>  $frames
     */
    public function __construct(
        public ?string $audioPath,
        public array $frames,
        public string $thumbnailPath,
    ) {}
}
