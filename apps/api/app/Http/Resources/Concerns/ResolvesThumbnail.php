<?php

namespace App\Http\Resources\Concerns;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\SourcePost;
use App\Services\Media\MediaUrlService;

/**
 * Resolves a source post's thumbnail (T-034/T-030 imagery). Prefers a signed
 * ffmpeg-derived `thumbnail` media_asset (the future yt-dlp path), and falls
 * back to the oEmbed poster the fetch step already captured into
 * `oembed_json['thumbnail_url']` — so YouTube/TikTok/Instagram shares show a
 * real reel image today without any download pipeline. Only http(s) URLs are
 * returned (an oEmbed provider could, in theory, hand back anything).
 */
trait ResolvesThumbnail
{
    private function resolveThumbnail(?SourcePost $post): ?string
    {
        if ($post === null) {
            return null;
        }

        /** @var MediaAsset|null $thumb */
        $thumb = $post->mediaAssets->first(fn (MediaAsset $a) => $a->kind === MediaKind::Thumbnail);
        if ($thumb !== null) {
            return app(MediaUrlService::class)->temporaryUrl($thumb->storage_path, $thumb->disk);
        }

        $oembed = $post->oembed_json['thumbnail_url'] ?? null;

        return is_string($oembed) && preg_match('#^https?://#i', $oembed) === 1 ? $oembed : null;
    }
}
