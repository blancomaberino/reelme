<?php

namespace App\Services\Media\Images;

use App\Enums\Platform;
use App\Models\SourcePost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves EVERY image of an Instagram photo/carousel post (T-013) — the real
 * upgrade over the oEmbed hero thumbnail. It calls Instagram's own web media API
 * (`/api/v1/media/{pk}/info/`) with the same session cookie a logged-in browser
 * sends, and reads one best image per slide from `carousel_media[].image_versions2`
 * (a video slide contributes its cover frame). yt-dlp can't do this: its Instagram
 * extractor only looks for video formats and errors ("No video formats found") on
 * image posts, cookies or not — so this direct API call is the resolver that
 * actually returns a carousel's menu slides.
 *
 * Auth is required: without a session cookie the endpoint 302s to login, so when
 * no readable cookie file is configured resolve() returns [] and the chain falls
 * through to OEmbedThumbnailResolver (the hero image only). The cookie is a
 * Netscape cookies.txt exported from a logged-in browser (see `cookies_path`).
 */
class InstagramApiResolver implements PostImageResolver
{
    /**
     * The public web app id the Instagram web client sends. The media info
     * endpoint requires it — without the header it redirects to the login page
     * instead of returning JSON.
     */
    private const APP_ID = '936619743392459';

    /** Alphabet Instagram uses to base64-encode a media pk into a shortcode. */
    private const SHORTCODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    public function __construct(
        private readonly ?string $cookiesPath = null,
        private readonly int $timeout = 15,
        private readonly bool $enabled = true,
    ) {}

    public function resolve(SourcePost $post): array
    {
        if (! $this->enabled || $post->platform !== Platform::Instagram) {
            return [];
        }

        // No session → this resolver can't authenticate; let the chain fall
        // through to the zero-auth oEmbed thumbnail rather than 302 to login.
        // Checked before the pk math so the no-cookie no-op does zero work.
        $cookie = $this->cookieHeader();
        if ($cookie === null) {
            return [];
        }

        $shortcode = $this->shortcode($post);
        $pk = $shortcode !== null ? $this->mediaPk($shortcode) : null;
        if ($pk === null) {
            return [];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withOptions(['allow_redirects' => false]) // an expired-cookie 302 to /login must not be followed to a 200 HTML page
                ->withHeaders([
                    'x-ig-app-id' => self::APP_ID,
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                    'Cookie' => $cookie,
                ])
                ->get("https://www.instagram.com/api/v1/media/{$pk}/info/");
        } catch (\Throwable $e) {
            // resolve() must never throw — the ingest chain treats a throw as
            // fatal — so a transport error just falls through to the next resolver.
            Log::debug('instagram_api.request_threw', [
                'source_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            // Expired cookie, rate limit, or a removed post — not fatal, drop to
            // the next resolver. A 4xx here is the signal the cookie needs refresh.
            Log::debug('instagram_api.request_failed', [
                'source_post_id' => $post->id,
                'status' => $response->status(),
            ]);

            return [];
        }

        $json = $response->json();

        return is_array($json) ? $this->imageUrls($json) : [];
    }

    /**
     * The post shortcode — the `external_id` set by the source adapter, or parsed
     * from the canonical /p//reel//tv/ URL as a fallback. Validated against the
     * shortcode alphabet so it can only ever be a real code (never injected into
     * the pk math or the request URL).
     */
    private function shortcode(SourcePost $post): ?string
    {
        $candidate = $post->external_id;
        if (! $this->isShortcode($candidate)
            && preg_match('#instagram\.com/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#i', $post->url, $m) === 1) {
            $candidate = $m[1];
        }

        return $this->isShortcode($candidate) ? $candidate : null;
    }

    private function isShortcode(?string $value): bool
    {
        // Real shortcodes are ~11 chars; the cap bounds the pk loop and request
        // URL against a pathological external_id/URL match (no injection — pk is
        // digits-only and the host is hardcoded — just wasted work).
        return is_string($value) && $value !== '' && strlen($value) <= 30
            && strspn($value, self::SHORTCODE_ALPHABET) === strlen($value);
    }

    /**
     * Decode a shortcode to its numeric media pk (base64 over the IG alphabet).
     * bcmath keeps the full 64-bit value exact — a native int cast would overflow
     * to a float and produce a wrong id for longer shortcodes.
     */
    private function mediaPk(string $shortcode): ?string
    {
        // Guard the never-throw contract even if ext-bcmath is somehow absent
        // (it's declared in composer.json require) — return null, not a fatal.
        if (! function_exists('bcmul')) {
            return null;
        }

        $pk = '0';
        foreach (str_split($shortcode) as $char) {
            $index = strpos(self::SHORTCODE_ALPHABET, $char);
            if ($index === false) {
                return null; // guarded by isShortcode(), but keep the math total
            }
            $pk = bcadd(bcmul($pk, '64'), (string) $index);
        }

        return $pk === '0' ? null : $pk;
    }

    /**
     * One best image per media node — each carousel slide (`carousel_media`) or
     * the single post — deduped, in slide order, https only.
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function imageUrls(array $json): array
    {
        $items = $json['items'] ?? null;
        $media = is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : null;
        if ($media === null) {
            return [];
        }

        $nodes = [];
        $carousel = $media['carousel_media'] ?? null;
        if (is_array($carousel) && $carousel !== []) {
            foreach ($carousel as $child) {
                if (is_array($child)) {
                    $nodes[] = $child;
                }
            }
        } else {
            $nodes[] = $media; // single-image (or single-video cover) post
        }

        $urls = [];
        foreach ($nodes as $node) {
            $best = $this->bestCandidate($node);
            if ($best !== null) {
                $urls[$best] = true; // key = dedupe, preserves first-seen order
            }
        }

        return array_keys($urls);
    }

    /**
     * The highest-resolution https candidate of a node's `image_versions2`. For a
     * video slide this is its cover frame, which is the representative still.
     *
     * @param  array<string, mixed>  $node
     */
    private function bestCandidate(array $node): ?string
    {
        $candidates = $node['image_versions2']['candidates'] ?? null;
        if (! is_array($candidates)) {
            return null;
        }

        $best = null;
        $bestArea = -1;
        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['url'] ?? null)) {
                continue;
            }
            if (preg_match('#^https://#i', $candidate['url']) !== 1) {
                continue;
            }
            $area = (int) ($candidate['width'] ?? 0) * (int) ($candidate['height'] ?? 0);
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $candidate['url'];
            }
        }

        return $best;
    }

    /**
     * Build a `Cookie:` header from the configured Netscape cookies.txt. Returns
     * null when no readable file is set, so resolve() can cleanly skip. Handles
     * the `#HttpOnly_` line prefix a browser export uses for HttpOnly cookies
     * (e.g. `sessionid`) — dropping those would strip the very cookie that authenticates.
     */
    private function cookieHeader(): ?string
    {
        if ($this->cookiesPath === null || ! is_file($this->cookiesPath) || ! is_readable($this->cookiesPath)) {
            return null;
        }

        $lines = file($this->cookiesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        $pairs = [];
        foreach ($lines as $line) {
            // FILE_IGNORE_NEW_LINES strips \n but not \r — a CRLF export (common
            // on Windows/browser extensions) would otherwise leave a trailing \r
            // on the cookie value and silently corrupt the Cookie header.
            $line = rtrim($line, "\r");

            // Keep `#HttpOnly_` rows (real cookies); skip genuine comment lines.
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif ($line === '' || $line[0] === '#') {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) >= 7 && $cols[5] !== '' && $cols[6] !== '') {
                $pairs[] = $cols[5].'='.$cols[6];
            }
        }

        return $pairs === [] ? null : implode('; ', $pairs);
    }
}
