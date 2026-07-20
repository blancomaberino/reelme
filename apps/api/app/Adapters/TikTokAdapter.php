<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\Support\FetchesOEmbed;
use App\Enums\Platform;

/**
 * Public TikTok post metadata via the keyless www.tiktok.com/oembed endpoint
 * (T-014, chain per 01 §5: oEmbed → yt-dlp → manual). Metadata only — the video
 * file comes from the yt-dlp step next in the chain.
 */
class TikTokAdapter implements SourceAdapter
{
    use FetchesOEmbed;

    public function supports(string $canonicalUrl): bool
    {
        // Per-platform kill switch (01 §5): force TikTok to manual-only, no deploy.
        if (! (bool) config('ingestion.platforms.tiktok.enabled', true)) {
            return false;
        }

        return $this->onTikTok($canonicalUrl);
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        if (! $this->onTikTok($canonicalUrl)) {
            throw new PostUnavailable('Unsupported TikTok URL.');
        }

        $body = $this->getOEmbed('https://www.tiktok.com/oembed', ['url' => $canonicalUrl]);

        return new SourcePostData(
            platform: Platform::Tiktok,
            externalId: $this->externalId($canonicalUrl),
            url: $canonicalUrl,
            // TikTok's oEmbed `title` is the caption, but is often empty — null
            // is correct then (never invent a caption).
            caption: $this->str($body['title'] ?? null),
            authorHandle: $this->str($body['author_unique_id'] ?? null),
            authorDisplayName: $this->str($body['author_name'] ?? null),
            // TikTok oEmbed carries no timestamp — posted_at stays null.
            raw: ['source' => 'tiktok-oembed'] + $body,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // The video file comes from the yt-dlp step next in the chain; oEmbed
        // exposes only a thumbnail + embed iframe.
        return new MediaFetchResult;
    }

    /** True for any tiktok.com host — full `www.` posts and `vm./vt.` shortlinks. */
    private function onTikTok(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // Suffix-anchored: `tiktok.com`, `www.tiktok.com`, `vm.tiktok.com`,
        // `vt.tiktok.com` all match; `tiktok.com.evil.com` does not.
        return $host === 'tiktok.com' || str_ends_with($host, '.tiktok.com');
    }

    /**
     * The numeric video id from `/@{user}/video/{id}`. Shortlink forms
     * (`vm.tiktok.com/{code}`, `tiktok.com/t/{code}`) carry no numeric id until
     * expanded (IngestShare normally does that first) — a stable URL hash keeps
     * source_posts unique in the meantime.
     */
    private function externalId(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#/video/(\d+)#', $path, $m) === 1
            ? $m[1]
            : substr(sha1($url), 0, 24);
    }
}
