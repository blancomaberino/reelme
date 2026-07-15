<?php

namespace App\Services\Media\Images;

use App\Enums\MediaKind;
use App\Jobs\Concerns\StreamsToDisk;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Media\MediaPaths;
use App\Services\Media\RemoteFileFetcher;
use Illuminate\Support\Facades\Log;

/**
 * Turns a photo/carousel post's images into `keyframe` media_assets so the model
 * sees them exactly like video keyframes (T-013). Runs the configured resolver
 * chain (first non-empty wins), downloads each image with the guarded fetcher,
 * validates it's a real image, and stores it. Called by PrepareMedia when the
 * post has no video.
 */
class PostImageIngestor
{
    use StreamsToDisk;

    /** Match the keyframe cap the prompt builder reads (frame_refs max). */
    private const MAX_IMAGES = 12;

    /**
     * @param  list<PostImageResolver>  $resolvers
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly RemoteFileFetcher $fetcher,
    ) {}

    /** Ingest the post's images as keyframes; returns how many were stored. */
    public function ingest(Share $share, SourcePost $post): int
    {
        $urls = [];
        foreach ($this->resolvers as $resolver) {
            $urls = $resolver->resolve($post);
            if ($urls !== []) {
                break; // first resolver that produces images wins
            }
        }
        if ($urls === []) {
            return 0;
        }

        $disk = (string) config('media.disk');
        $shareId = (string) $share->id;
        $stored = 0;

        foreach (array_slice($urls, 0, self::MAX_IMAGES) as $index => $url) {
            $tmp = null;
            try {
                $tmp = $this->fetcher->fetchToTemp($url);
                $size = @getimagesize($tmp);
                if ($size === false) {
                    continue; // not a decodable image — skip
                }

                // Dedupe on content before writing so a repeated slide never
                // leaves an orphaned blob on disk (checked, then stored).
                $sha256 = (string) hash_file('sha256', $tmp);
                if (MediaAsset::where('sha256', $sha256)->where('source_post_id', $post->id)->exists()) {
                    continue;
                }

                // frame_at_ms spaces the images so they keep display order in the
                // prompt (which orders keyframes by frame_at_ms).
                $atMs = $index * 1000;
                $this->writeStreamFromFile($disk, MediaPaths::frame($shareId, $index, $atMs), $tmp);

                MediaAsset::create([
                    'sha256' => $sha256,
                    'source_post_id' => $post->id,
                    'kind' => MediaKind::Keyframe,
                    'storage_path' => MediaPaths::frame($shareId, $index, $atMs),
                    'disk' => $disk,
                    'mime' => $size['mime'],
                    'bytes' => filesize($tmp) ?: 0,
                    'frame_at_ms' => $atMs,
                    'width' => $size[0],
                    'height' => $size[1],
                ]);
                $stored++;
            } catch (\Throwable $e) {
                // Host, not the full URL (may carry a signed query), + the reason
                // so an SSRF block is distinguishable from a 404/cap/decode fail.
                Log::warning('post_image.fetch_failed', [
                    'source_post_id' => $post->id,
                    'host' => parse_url($url, PHP_URL_HOST),
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if ($tmp !== null) {
                    @unlink($tmp);
                }
            }
        }

        return $stored;
    }
}
