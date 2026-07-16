<?php

namespace App\Services\Media\Images;

use App\Enums\Platform;
use App\Models\SourcePost;
use App\Services\Media\Instagram\InstagramWebClient;

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
 * through to OEmbedThumbnailResolver (the hero image only). The cookie/header/
 * redirect plumbing lives in the shared InstagramWebClient (T-075); this resolver
 * keeps only the shortcode→pk math and the carousel image parsing.
 */
class InstagramApiResolver implements PostImageResolver
{
    /** Alphabet Instagram uses to base64-encode a media pk into a shortcode. */
    private const SHORTCODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    private readonly InstagramWebClient $client;

    public function __construct(
        ?string $cookiesPath = null,
        int $timeout = 15,
        bool $enabled = true,
    ) {
        $this->client = new InstagramWebClient($cookiesPath, $timeout, $enabled);
    }

    public function resolve(SourcePost $post): array
    {
        // Not IG, disabled, or no session cookie → let the chain fall through to
        // the zero-auth oEmbed thumbnail rather than 302 to login. ready() is
        // checked before the pk math so the no-op does zero work.
        if ($post->platform !== Platform::Instagram || ! $this->client->ready()) {
            return [];
        }

        $shortcode = $this->shortcode($post);
        $pk = $shortcode !== null ? $this->mediaPk($shortcode) : null;
        if ($pk === null) {
            return [];
        }

        // never throws → null on any transport error / non-2xx, so we fall
        // through to the next resolver.
        $json = $this->client->mediaInfo($pk);

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
}
