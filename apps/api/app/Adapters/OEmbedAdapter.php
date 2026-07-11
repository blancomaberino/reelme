<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Keyless metadata fetch via public oEmbed endpoints (YouTube, TikTok) — a
 * no-credentials way to turn a real link into caption/author for the text
 * extraction path (a lightweight slice of T-014). It fetches metadata only; media
 * bytes need yt-dlp/auth (T-013+), so fetchMedia is empty and the pipeline runs
 * text-only on the title.
 *
 * Providers whose oEmbed needs a token or is deprecated (Instagram, X) are simply
 * unsupported here — those chains fall through to the manual fallback.
 */
class OEmbedAdapter implements SourceAdapter
{
    /** host suffix => [platform, oEmbed endpoint]. */
    private const PROVIDERS = [
        'youtube.com' => [Platform::Youtube, 'https://www.youtube.com/oembed'],
        'youtu.be' => [Platform::Youtube, 'https://www.youtube.com/oembed'],
        'tiktok.com' => [Platform::Tiktok, 'https://www.tiktok.com/oembed'],
    ];

    public function supports(string $canonicalUrl): bool
    {
        return $this->provider($canonicalUrl) !== null;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        $provider = $this->provider($canonicalUrl);
        if ($provider === null) {
            throw new PostUnavailable("Unsupported oEmbed URL [{$canonicalUrl}].");
        }
        [$platform, $endpoint] = $provider;

        try {
            $response = Http::timeout((int) config('ingestion.oembed.timeout', 10))
                ->get($endpoint, ['url' => $canonicalUrl, 'format' => 'json']);
        } catch (ConnectionException) {
            // Never interpolate the URL/message — transient, advance the chain.
            throw new FetchFailed('oEmbed request failed.');
        }

        if ($response->status() === 404 || $response->status() === 401 || $response->status() === 403) {
            throw new PostUnavailable('Post is unavailable or private.');
        }
        if ($response->failed()) {
            throw new FetchFailed('oEmbed returned '.$response->status().'.');
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new PostUnavailable('oEmbed response had no title.');
        }

        return new SourcePostData(
            platform: $platform,
            externalId: $this->externalId($canonicalUrl),
            url: $canonicalUrl,
            // The title is the only text a public oEmbed exposes — it becomes the
            // caption the extractor reads.
            caption: $title,
            authorHandle: $this->authorHandle($body),
            authorDisplayName: ($body['author_name'] ?? null) !== null ? (string) $body['author_name'] : null,
            raw: ['source' => 'oembed'] + $body,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // oEmbed exposes no downloadable media URL (only an embed iframe) — the
        // pipeline runs text-only on the caption. Real media needs yt-dlp (T-013+).
        return new MediaFetchResult;
    }

    /**
     * @return array{0: Platform, 1: string}|null
     */
    private function provider(string $canonicalUrl): ?array
    {
        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));

        foreach (self::PROVIDERS as $domain => $provider) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function authorHandle(array $body): ?string
    {
        $authorUrl = (string) ($body['author_url'] ?? '');
        if (preg_match('/@([A-Za-z0-9._]+)/', $authorUrl, $m) === 1) {
            return $m[1];
        }

        $name = trim((string) ($body['author_name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function externalId(string $url): string
    {
        // youtu.be/<id>, watch?v=<id>, /video/<id>; else a stable hash of the URL.
        if (preg_match('#(?:youtu\.be/|[?&]v=)([A-Za-z0-9_-]{6,})#', $url, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#/video/(\d+)#', $url, $m) === 1) {
            return $m[1];
        }

        return substr(sha1($url), 0, 24);
    }
}
