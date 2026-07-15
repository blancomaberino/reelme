<?php

namespace App\Services\Media\Images;

use App\Models\SourcePost;

/**
 * The zero-auth fallback resolver (T-013): the post's oEmbed thumbnail — the
 * hero/first image, already fetched by FetchSourcePost. It won't return the rest
 * of a carousel (that needs an authenticated resolver like yt-dlp), but it lets
 * the model finally SEE an image when the menu is the hero shot, with no
 * Instagram credentials.
 */
class OEmbedThumbnailResolver implements PostImageResolver
{
    public function resolve(SourcePost $post): array
    {
        $url = $post->oembed_json['thumbnail_url'] ?? null;

        return is_string($url) && preg_match('#^https://#i', $url) === 1 ? [$url] : [];
    }
}
