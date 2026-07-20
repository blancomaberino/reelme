<?php

namespace App\Adapters;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Enums\MediaKind;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Fetches a video post's caption + author AND downloads its real video with
 * yt-dlp (T-074), so the pipeline sees actual scene keyframes, audio, and the
 * full caption — not the caption-only fallback. yt-dlp's Instagram extractor only
 * ever yields video, so image/carousel posts are covered by `InstagramApiResolver`,
 * not here (that mis-scoping closed PR #80).
 *
 * `fetchMetadata()` runs `yt-dlp -J … -- <url>` (dump JSON, no download) → the
 * caption (`description`), author, and posted date; it is the rescue when the
 * keyless oEmbed reports the post unavailable (common for Instagram from a
 * datacenter IP), so a reel no longer dead-ends at the manual fallback. It throws
 * the usual typed adapter exceptions (advance the chain) on any yt-dlp failure.
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
    /** Host suffix => platform yt-dlp reliably handles (04 §2). */
    private const SUPPORTED_HOSTS = [
        'instagram.com' => Platform::Instagram,
        'tiktok.com' => Platform::Tiktok,
        'youtube.com' => Platform::Youtube,
        'youtu.be' => Platform::Youtube,
        // X video posts (T-014): the metadata adapter is oEmbed, the media is
        // yt-dlp's twitter extractor. Image-only tweets simply yield no video.
        'x.com' => Platform::X,
        'twitter.com' => Platform::X,
    ];

    public function __construct(
        private readonly string $bin = 'yt-dlp',
        private readonly int $timeout = 120,
        private readonly ?string $cookiesPath = null,
        private readonly bool $enabled = true,
    ) {}

    public function supports(string $canonicalUrl): bool
    {
        return $this->platformFor($canonicalUrl) !== null;
    }

    /** The platform for a supported http(s) URL, else null. */
    private function platformFor(string $canonicalUrl): ?Platform
    {
        if (preg_match('#^https?://#i', $canonicalUrl) !== 1) {
            return null;
        }

        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));

        foreach (self::SUPPORTED_HOSTS as $domain => $platform) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $platform;
            }
        }

        return null;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    /**
     * The post's caption + author from `yt-dlp -J` (dump JSON, no download) — the
     * rescue when the keyless oEmbed is blocked/rate-limited. Throws PostUnavailable
     * (advance the chain to the manual fallback) when yt-dlp is disabled, the URL
     * is unsupported, the binary is missing, the fetch fails/times out, or the
     * payload is unusable — mirroring how the oEmbed adapters signal the chain.
     */
    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        $platform = $this->platformFor($canonicalUrl);
        if (! $this->enabled || $platform === null) {
            throw new PostUnavailable('yt-dlp disabled or unsupported URL.');
        }

        try {
            $result = Process::timeout($this->timeout)->run($this->metadataCommand($canonicalUrl));
        } catch (\Throwable $e) {
            // A hang past the timeout throws; treat as unavailable so the chain
            // advances rather than the fetch job failing.
            Log::debug('ytdlp.metadata_threw', ['error' => $e->getMessage()]);

            throw new PostUnavailable('yt-dlp metadata fetch failed.');
        }

        if (! $result->successful()) {
            // Missing binary, an auth wall, or an unsupported post — advance the
            // chain. A 4xx/auth error is the signal to refresh the cookie file.
            Log::debug('ytdlp.metadata_failed', [
                'exit' => $result->exitCode(),
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);

            throw new PostUnavailable('yt-dlp could not fetch metadata.');
        }

        $json = json_decode(trim($result->output()), true);
        $id = is_array($json) ? ($json['id'] ?? null) : null;
        if (! is_array($json) || ! is_string($id) || $id === '') {
            throw new PostUnavailable('yt-dlp returned no usable metadata.');
        }

        return new SourcePostData(
            platform: $platform,
            externalId: $id,
            url: $canonicalUrl,
            // The caption is the real content; the generic "Video by <poster>"
            // title is only a last resort. The poster (author) is the reviewer,
            // NOT the venue — the extractor (POSTED BY, v8) knows to exclude it.
            caption: $this->str($json['description'] ?? null) ?? $this->str($json['title'] ?? null),
            authorHandle: $this->str($json['channel'] ?? null) ?? $this->str($json['uploader'] ?? null),
            authorDisplayName: $this->str($json['uploader'] ?? null) ?? $this->str($json['channel'] ?? null),
            postedAt: $this->postedAt($json),
            raw: ['source' => 'ytdlp'],
        );
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

            // A SIGKILL'd yt-dlp can leave a partial `<stem>.<ext>` (--no-part
            // writes straight to the final name); discard() sweeps it so a failed
            // download never leaks disk on a long-running worker.
            return $this->discard($stem);
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

            // A mid-download non-zero exit may have written partial bytes.
            return $this->discard($stem);
        }

        $path = $this->downloadedPath($result->output());
        if ($path === null) {
            // yt-dlp succeeded but printed no path — the finished file (if any)
            // would otherwise be orphaned, since only the success return hands
            // it to DownloadMedia to clean.
            return $this->discard($stem);
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
            // --quiet (does NOT suppress --print) keeps stdout to just the
            // printed path, so downloadedPath() stays deterministic even if a
            // post-processor is added later. Errors still go to stderr.
            '--quiet',
            '--no-warnings',
            '--no-progress',
            '--no-part',
            '-o', $stem.'.%(ext)s',
            '--print', 'after_move:filepath',
            '--no-simulate',
        ];

        // `--` ends option parsing so the URL can never be read as a flag, even
        // if a future caller bypasses the scheme guard in fetchMedia().
        return [...$cmd, ...$this->cookieArgs(), '--', $url];
    }

    /**
     * The metadata invocation: `-J` dumps a single JSON blob for the post (no
     * download). `--` guards the URL against being read as a flag.
     *
     * @return array<int, string>
     */
    private function metadataCommand(string $url): array
    {
        return [$this->bin, '-J', '--no-warnings', '--no-progress', ...$this->cookieArgs(), '--', $url];
    }

    /**
     * `--cookies <path>` when a readable cookie file is configured, else nothing —
     * shared by the metadata and download invocations.
     *
     * @return array<int, string>
     */
    private function cookieArgs(): array
    {
        return $this->cookiesPath !== null && is_file($this->cookiesPath)
            ? ['--cookies', $this->cookiesPath]
            : [];
    }

    /** A trimmed, non-empty string from a possibly-missing/non-string value, else null. */
    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The post's timestamp from yt-dlp's `timestamp` (unix seconds) or
     * `upload_date` (YYYYMMDD), else null. Never throws — a malformed date just
     * leaves posted_at unset.
     *
     * @param  array<string, mixed>  $json
     */
    private function postedAt(array $json): ?CarbonImmutable
    {
        try {
            if (is_int($json['timestamp'] ?? null) && $json['timestamp'] > 0) {
                return CarbonImmutable::createFromTimestamp($json['timestamp']);
            }
            $date = $this->str($json['upload_date'] ?? null);
            if ($date !== null && preg_match('/^\d{8}$/', $date) === 1) {
                return CarbonImmutable::createFromFormat('!Ymd', $date) ?: null;
            }
        } catch (\Throwable) {
            // fall through to null
        }

        return null;
    }

    /**
     * The final file path yt-dlp printed (`after_move:filepath`) — the last
     * non-empty stdout line. Null when yt-dlp printed nothing usable (e.g. it
     * succeeded but produced no file), so fetchMedia falls through cleanly.
     */
    private function downloadedPath(string $output): ?string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $output) ?: []),
            fn (string $line): bool => $line !== '',
        ));

        return $lines === [] ? null : end($lines);
    }

    /**
     * A failure return: sweep any leftover file for this stem, then hand back an
     * empty result. Only the success path returns media (and hands its file to
     * DownloadMedia to clean) — never sweep there.
     */
    private function discard(string $stem): MediaFetchResult
    {
        $this->sweep($stem);

        return new MediaFetchResult;
    }

    /**
     * Remove any file yt-dlp wrote for a reserved stem (`<stem>.<ext>`, plus any
     * sidecar) on a failure path.
     */
    private function sweep(string $stem): void
    {
        foreach (glob($stem.'.*') ?: [] as $leftover) {
            @unlink($leftover);
        }
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
