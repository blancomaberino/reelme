<?php

namespace App\Services\Media\Images;

use App\Models\SourcePost;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Resolves a post's images via yt-dlp (T-013) — the real upgrade over the oEmbed
 * hero thumbnail: `yt-dlp -J <url>` returns metadata for EVERY slide of a
 * carousel (and a cover frame for a video slide), so the model sees the whole
 * post, not just the first image. yt-dlp talks to the platform's internal API,
 * so private/rate-limited posts need an authenticated cookie file (see the
 * `cookies_path` config). When yt-dlp is missing or fails, resolve() returns []
 * and the chain falls through to OEmbedThumbnailResolver.
 */
class YtDlpResolver implements PostImageResolver
{
    /** Extensions yt-dlp reports for an image-only entry. */
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    public function __construct(
        private readonly string $bin = 'yt-dlp',
        private readonly int $timeout = 45,
        private readonly ?string $cookiesPath = null,
        private readonly bool $enabled = true,
    ) {}

    public function resolve(SourcePost $post): array
    {
        // Require a real web URL. This is also the argument-injection guard: a
        // URL that can't start with `-` can't be read by yt-dlp as a flag
        // (e.g. --exec) — and it skips manual:// caption shares.
        if (! $this->enabled || preg_match('#^https?://#i', $post->url) !== 1) {
            return [];
        }

        try {
            $result = Process::timeout($this->timeout)->run($this->command($post->url));
        } catch (\Throwable $e) {
            // A yt-dlp hang past the timeout throws ProcessTimedOutException.
            // resolve() must never throw — the ingest chain treats a throw as
            // fatal — so fall through to the next resolver instead.
            Log::debug('ytdlp.resolve_threw', [
                'source_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $result->successful()) {
            // Missing binary, auth wall, or an unsupported URL — not fatal: the
            // resolver chain drops to the next resolver.
            Log::debug('ytdlp.resolve_failed', [
                'source_post_id' => $post->id,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);

            return [];
        }

        $json = json_decode(trim($result->output()), true);

        return is_array($json) ? $this->imageUrls($json) : [];
    }

    /**
     * `-J` dumps a single JSON blob (a playlist for a carousel). No download,
     * cookies only when a readable file is configured.
     *
     * @return list<string>
     */
    private function command(string $url): array
    {
        $cmd = [$this->bin, '-J', '--no-warnings', '--no-progress'];

        if ($this->cookiesPath !== null && is_file($this->cookiesPath)) {
            $cmd[] = '--cookies';
            $cmd[] = $this->cookiesPath;
        }

        // `--` ends option parsing so the URL can never be read as a flag, even
        // if a future caller bypasses the scheme guard in resolve().
        $cmd[] = '--';
        $cmd[] = $url;

        return $cmd;
    }

    /**
     * One best image per media node — each carousel slide (playlist `entries`)
     * or the single post — deduped, in slide order, https only.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function imageUrls(array $json): array
    {
        $nodes = [];
        if (isset($json['entries']) && is_array($json['entries'])) {
            foreach ($json['entries'] as $entry) {
                if (is_array($entry)) {
                    $nodes[] = $entry;
                }
            }
        } else {
            $nodes[] = $json;
        }

        $urls = [];
        foreach ($nodes as $node) {
            $best = $this->bestImage($node);
            if (is_string($best) && preg_match('#^https://#i', $best) === 1) {
                $urls[$best] = true; // key = dedupe, preserves first-seen order
            }
        }

        return array_keys($urls);
    }

    /** @param array<string, mixed> $node */
    private function bestImage(array $node): ?string
    {
        // Full-res still on an IG image slide.
        if (is_string($node['display_url'] ?? null) && $node['display_url'] !== '') {
            return $node['display_url'];
        }

        // An entry whose own media is an image (image ext, or no video codec).
        $ext = is_string($node['ext'] ?? null) ? strtolower($node['ext']) : null;
        $isImageEntry = ($ext !== null && in_array($ext, self::IMAGE_EXTS, true))
            || ($node['vcodec'] ?? null) === 'none';
        if ($isImageEntry && is_string($node['url'] ?? null) && $node['url'] !== '') {
            return $node['url'];
        }

        // Otherwise (a video slide, or an image only exposed via thumbnails) the
        // largest thumbnail is a representative cover frame.
        return $this->largestThumbnail($node);
    }

    /** @param array<string, mixed> $node */
    private function largestThumbnail(array $node): ?string
    {
        $thumbs = $node['thumbnails'] ?? null;
        if (is_array($thumbs) && $thumbs !== []) {
            $best = null;
            $bestArea = -1;
            foreach ($thumbs as $thumb) {
                if (! is_array($thumb) || ! is_string($thumb['url'] ?? null)) {
                    continue;
                }
                // yt-dlp orders thumbnails worst→best; `>=` lets a later
                // unknown-size (area 0) entry still win over nothing.
                $area = (int) ($thumb['width'] ?? 0) * (int) ($thumb['height'] ?? 0);
                if ($area >= $bestArea) {
                    $bestArea = $area;
                    $best = $thumb['url'];
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return is_string($node['thumbnail'] ?? null) && $node['thumbnail'] !== ''
            ? $node['thumbnail']
            : null;
    }
}
