<?php

namespace App\Services\Media\Images;

use App\Models\SourcePost;

/**
 * Resolves the image URLs of a photo/carousel post so the model can see them
 * (T-013). Implementations form a priority chain (config `ingestion.image_resolvers`);
 * the first that returns any URLs wins — e.g. a future yt-dlp resolver (all
 * carousel slides) ahead of the oEmbed thumbnail fallback (the hero image only).
 * Swapping in a paid-API resolver later is a one-line config change.
 */
interface PostImageResolver
{
    /**
     * Public https image URLs for the post, in display order. Empty when this
     * resolver can't produce any (the chain then tries the next resolver).
     *
     * @return list<string>
     */
    public function resolve(SourcePost $post): array;
}
