<?php

namespace App\Services\Media\Data;

/**
 * One extracted keyframe on local disk. `index` is the stable 0-based position
 * in chronological order — this IS the `frame_refs` contract the extraction
 * schema (T-021) references, so it must always match ascending `atMs`.
 */
final readonly class ExtractedFrame
{
    public function __construct(
        public int $index,
        public int $atMs,
        public string $path,
    ) {}
}
