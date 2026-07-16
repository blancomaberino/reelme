<?php

namespace App\Adapters;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Enums\MediaKind;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Downloads a post's actual video with yt-dlp (T-074) so the pipeline sees REAL
 * scene keyframes and audio, not just the caption. It is a media-only adapter:
 * `OEmbedAdapter` earlier in the chain still supplies the caption/author, and
 * yt-dlp's Instagram extractor only ever yields video — image/carousel posts are
 * covered by `InstagramApiResolver`, not here (that mis-scoping closed PR #80).
 *
 * `fetchMedia()` runs `yt-dlp … -o <temp> -- <url>` and returns the downloaded
 * file as a `FetchedMedia(localPath:…, kind: Video)`; `DownloadMedia` stores it
 * and `PrepareMedia` extracts frames. It NEVER throws — a missing binary, an
 * image post ("No video formats found"), an auth wall, a timeout, or being
 * disabled all return an empty result so the caption-only path stays the
 * graceful fallback. yt-dlp reaches private/rate-limited posts only with an
 * authenticated cookie file (see `cookies_path`).
 */
class YtDlpAdapter implements SourceAdapter
{
    /** Host suffixes yt-dlp reliably downloads video from (04 §2). */
    private const SUPPORTED_HOSTS = ['instagram.com', 'tiktok.com', 'youtube.com', 'youtu.be'];

    public function __construct(
        private readonly string $bin = 'yt-dlp',
        private readonly int $timeout = 120,
        private readonly ?string $cookiesPath = null,
        private readonly bool $enabled = true,
    ) {}

    public function supports(string $canonicalUrl): bool
    {
        if (preg_match('#^https?://#i', $canonicalUrl) !== 1) {
            return false;
        }

        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));

        foreach (self::SUPPORTED_HOSTS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    /**
     * yt-dlp is a media-only adapter — the caption/author come from
     * `OEmbedAdapter` earlier in the chain. Declining here advances the chain
     * (to the manual fallback) exactly like an unavailable post would.
     */
    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        throw new PostUnavailable('yt-dlp resolves media only; metadata comes from oEmbed.');
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // Require a real web URL. This is also the argument-injection guard: a
        // value that can't start with `-` can never be read by yt-dlp as a flag
        // (e.g. --exec) — and it skips manual:// caption shares.
        if (! $this->enabled || preg_match('#^https?://#i', $post->url) !== 1) {
            return new MediaFetchResult;
        }

        // A unique, flat temp stem in the system temp dir (yt-dlp appends the
        // real container extension). DownloadMedia consumes and unlinks the
        // resulting file — a flat file (not a subdir) keeps that cleanup simple.
        $stem = (string) tempnam(sys_get_temp_dir(), 'reel_ytdlp_');
        @unlink($stem); // reserve the name only; yt-dlp writes "<stem>.<ext>"

        try {
            $result = Process::timeout($this->timeout)->run($this->command($post->url, $stem));
        } catch (\Throwable $e) {
            // A yt-dlp hang past the timeout throws ProcessTimedOutException.
            // fetchMedia() must never throw — the download chain treats a throw
            // as fatal — so fall through to the caption-only path instead.
            Log::debug('ytdlp.fetch_threw', [
                'source_post_id' => $post->externalId,
                'error' => $e->getMessage(),
            ]);

            return new MediaFetchResult;
        }

        if (! $result->successful()) {
            // Missing binary, an image post ("No video formats found"), an auth
            // wall, or an unsupported URL — none fatal: fall through to the
            // caption-only path. A 4xx/auth error is the signal to refresh the
            // cookie file (see `cookies_path`).
            Log::debug('ytdlp.fetch_failed', [
                'source_post_id' => $post->externalId,
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);

            return new MediaFetchResult;
        }

        $path = $this->downloadedPath($result->output());
        if ($path === null) {
            return new MediaFetchResult;
        }

        return new MediaFetchResult([
            new FetchedMedia(
                kind: MediaKind::Video,
                localPath: $path,
                mime: $this->mimeFor($path),
            ),
        ]);
    }

    /**
     * The yt-dlp invocation: download the single best video (never a playlist)
     * to `<stem>.<ext>` and print the final path so we know the exact filename
     * yt-dlp chose. `--print` implies `--simulate`, so `--no-simulate` is
     * required to actually download alongside the print.
     *
     * @return array<int, string>
     */
    private function command(string $url, string $stem): array
    {
        $cmd = [
            $this->bin,
            '--no-playlist',
            '--no-warnings',
            '--no-progress',
            '--no-part',
            '-o', $stem.'.%(ext)s',
            '--print', 'after_move:filepath',
            '--no-simulate',
        ];

        if ($this->cookiesPath !== null && is_file($this->cookiesPath)) {
            $cmd[] = '--cookies';
            $cmd[] = $this->cookiesPath;
        }

        // `--` ends option parsing so the URL can never be read as a flag, even
        // if a future caller bypasses the scheme guard in fetchMedia().
        $cmd[] = '--';
        $cmd[] = $url;

        return $cmd;
    }

    /**
     * The final file path yt-dlp printed (`after_move:filepath`) — the last
     * non-empty stdout line. Null when yt-dlp printed nothing usable (e.g. it
     * succeeded but produced no file), so fetchMedia falls through cleanly.
     */
    private function downloadedPath(string $output): ?string
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }
}
