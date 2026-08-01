<?php

namespace App\Services\Ingestion;

use App\Enums\Platform;
use App\Models\SourcePost;

/**
 * The outcome of resolving an incoming share to a `source_posts` row (T-109).
 *
 * `platform` is the platform we are prepared to TELL THE CLIENT — which is not
 * always the one stored on the post. `source_posts.platform` is NOT NULL with
 * four fixed values (02 §3.4), so a share from an unknown host has to be stored
 * under a placeholder; reporting that placeholder back would be a lie the
 * client then displays. See {@see SourcePostResolver} and ADR-109.
 */
final readonly class ResolvedSource
{
    public function __construct(
        public SourcePost $post,
        public ?Platform $platform = null,
    ) {}
}
