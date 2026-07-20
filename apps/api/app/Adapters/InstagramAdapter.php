<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\Support\FetchesOEmbed;
use App\Enums\Platform;

/**
 * Public Instagram post metadata via Instagram's keyless — but undocumented and
 * IP-rate-limited — oEmbed endpoint (a lightweight slice of T-013): a
 * no-credentials way to turn a reel/post link into caption/author for the text
 * extraction path. Metadata only; media bytes need yt-dlp/auth (the chain's next
 * step), so fetchMedia is empty and the pipeline runs text-only on the title.
 *
 * (Was `OEmbedAdapter`, a generic multi-provider oEmbed adapter — X, TikTok, and
 * YouTube now have dedicated adapters (T-014), leaving this Instagram-only. The
 * shared oEmbed plumbing lives in the FetchesOEmbed trait.)
 */
class InstagramAdapter implements SourceAdapter
{
    use FetchesOEmbed;

    private const ENDPOINT = 'https://www.instagram.com/api/v1/oembed/';

    public function supports(string $canonicalUrl): bool
    {
        return $this->hostMatches($canonicalUrl, ['instagram.com']);
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        if (! $this->supports($canonicalUrl)) {
            // Never interpolate the URL into the message (log-leak policy).
            throw new PostUnavailable('Unsupported Instagram URL.');
        }

        // Instagram's endpoint is keyless but best-effort — a block/limit maps to
        // PostUnavailable/FetchFailed inside getOEmbed() (→ manual review).
        $body = $this->getOEmbed(self::ENDPOINT, ['url' => $canonicalUrl, 'format' => 'json']);

        $title = $this->str($body['title'] ?? null);
        if ($title === null) {
            throw new PostUnavailable('oEmbed response had no title.');
        }

        return new SourcePostData(
            platform: Platform::Instagram,
            externalId: $this->externalId($canonicalUrl),
            url: $canonicalUrl,
            // The title is the only text a public oEmbed exposes — it becomes the
            // caption the extractor reads.
            caption: $title,
            authorHandle: $this->authorHandle($body),
            authorDisplayName: $this->str($body['author_name'] ?? null),
            raw: ['source' => 'oembed'] + $body,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // oEmbed exposes no downloadable media URL (only an embed iframe) — the
        // video comes from the yt-dlp step next in the chain.
        return new MediaFetchResult;
    }

    /** The shortcode from /p/, /reel/, /reels/, /tv/, else a stable hash of the URL. */
    private function externalId(string $url): string
    {
        if (preg_match('#/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $url, $m) === 1) {
            return $m[1];
        }

        return substr(sha1($url), 0, 24);
    }

    /**
     * The @handle from author_url when present, else the author_name.
     *
     * @param  array<string, mixed>  $body
     */
    private function authorHandle(array $body): ?string
    {
        $authorUrl = $this->str($body['author_url'] ?? null) ?? '';
        if (preg_match('/@([A-Za-z0-9._]+)/', $authorUrl, $m) === 1) {
            return $m[1];
        }

        return $this->str($body['author_name'] ?? null);
    }
}
