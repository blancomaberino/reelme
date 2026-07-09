<?php

namespace App\Services\Ingestion;

use App\Enums\Platform;

/**
 * A canonicalized share URL: tracking params stripped, shortlinks expanded, with
 * the resolved platform and platform post id (used to firstOrCreate source_posts
 * on (platform, external_id)). `platform`/`externalId` are null for unknown
 * hosts / manual shares.
 */
final readonly class CanonicalUrl
{
    public function __construct(
        public string $url,
        public ?Platform $platform = null,
        public ?string $externalId = null,
    ) {}
}
