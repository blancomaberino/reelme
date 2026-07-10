<?php

namespace App\Adapters\Data;

use App\Enums\Platform;
use Carbon\CarbonImmutable;

/**
 * Post metadata resolved by a SourceAdapter (04 §2). Media descriptors advertise
 * what exists; actual bytes are fetched separately via fetchMedia().
 */
final readonly class SourcePostData
{
    /**
     * @param  array<int, MediaDescriptor>  $media
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public Platform $platform,
        public string $externalId,
        public string $url,
        public ?string $caption = null,
        public ?string $authorHandle = null,
        public ?string $authorDisplayName = null,
        public ?CarbonImmutable $postedAt = null,
        public array $media = [],
        public array $raw = [],
    ) {}
}
