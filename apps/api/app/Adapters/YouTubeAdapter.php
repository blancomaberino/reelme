<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\Support\FetchesOEmbed;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;

/**
 * Public YouTube post metadata (T-014, chain per 01 §5: Data API/oEmbed → yt-dlp
 * → manual — lowest ToS risk, official APIs preferred). When
 * `services.youtube.api_key` is set it uses the Data API v3 (full description as
 * caption, channel, publishedAt); otherwise it silently falls back to the
 * keyless oEmbed (title + author only). Covers watch, youtu.be, and Shorts URLs.
 */
class YouTubeAdapter implements SourceAdapter
{
    use FetchesOEmbed;

    public function supports(string $canonicalUrl): bool
    {
        // Per-platform kill switch (01 §5): force YouTube to manual-only, no deploy.
        if (! (bool) config('ingestion.platforms.youtube.enabled', true)) {
            return false;
        }

        return $this->videoId($canonicalUrl) !== null;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        $id = $this->videoId($canonicalUrl);
        if ($id === null) {
            throw new PostUnavailable('Unsupported YouTube URL.');
        }

        $key = $this->str(config('services.youtube.api_key'));

        // A missing key must silently use oEmbed, never error (07 R-01).
        return $key !== null
            ? $this->viaDataApi($canonicalUrl, $id, $key)
            : $this->viaOEmbed($canonicalUrl, $id);
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // The video file (Shorts included) comes from the yt-dlp step next in the
        // chain; neither the Data API nor oEmbed exposes downloadable media.
        return new MediaFetchResult;
    }

    /** Data API v3: full description as caption, channelTitle, ISO-8601 publishedAt. */
    private function viaDataApi(string $url, string $id, string $key): SourcePostData
    {
        try {
            $response = $this->http()->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'snippet,contentDetails',
                'id' => $id,
                'key' => $key,
            ]);
        } catch (ConnectionException) {
            throw new FetchFailed('YouTube API request failed.');
        }

        $this->guard($response);

        $items = $response->json('items');
        $item = is_array($items) && isset($items[0]) && is_array($items[0]) ? $items[0] : null;
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : null;
        if ($item === null || $snippet === null) {
            // Empty items = deleted, private, or region-blocked video.
            throw new PostUnavailable('YouTube video is unavailable.');
        }

        return new SourcePostData(
            platform: Platform::Youtube,
            externalId: $id,
            url: $url,
            // The full description is the real content; the title is the fallback.
            // Descriptions can be huge — no truncation (DB caption is `text`).
            caption: $this->str($snippet['description'] ?? null) ?? $this->str($snippet['title'] ?? null),
            authorHandle: $this->str($snippet['channelTitle'] ?? null),
            authorDisplayName: $this->str($snippet['channelTitle'] ?? null),
            postedAt: $this->postedAt($this->str($snippet['publishedAt'] ?? null)),
            raw: ['source' => 'youtube-api'] + $item,
        );
    }

    /** Keyless oEmbed fallback: title + author only, no timestamp. */
    private function viaOEmbed(string $url, string $id): SourcePostData
    {
        $body = $this->getOEmbed('https://www.youtube.com/oembed', ['url' => $url, 'format' => 'json']);

        $title = $this->str($body['title'] ?? null);
        if ($title === null) {
            throw new PostUnavailable('oEmbed response had no title.');
        }

        return new SourcePostData(
            platform: Platform::Youtube,
            externalId: $id,
            url: $url,
            caption: $title,
            authorHandle: $this->handleFromAuthorUrl($body),
            authorDisplayName: $this->str($body['author_name'] ?? null),
            raw: ['source' => 'youtube-oembed'] + $body,
        );
    }

    /**
     * The 11-char (min 6 for forward-compat) video id from watch?v=, youtu.be/,
     * /shorts/, /embed/, /v/ — else null. Suffix-anchored host check first.
     */
    private function videoId(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $isShortHost = $host === 'youtu.be' || str_ends_with($host, '.youtu.be');
        $isMainHost = $host === 'youtube.com' || str_ends_with($host, '.youtube.com');
        if (! $isShortHost && ! $isMainHost) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        // youtu.be/{id}
        if ($isShortHost) {
            return preg_match('#^/([A-Za-z0-9_-]{6,})#', $path, $m) === 1 ? $m[1] : null;
        }

        // /shorts/{id}, /embed/{id}, /v/{id}
        if (preg_match('#^/(?:shorts|embed|v)/([A-Za-z0-9_-]{6,})#', $path, $m) === 1) {
            return $m[1];
        }

        // watch?v={id}
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $v = $query['v'] ?? null;

        return is_string($v) && preg_match('#^[A-Za-z0-9_-]{6,}$#', $v) === 1 ? $v : null;
    }

    private function postedAt(?string $iso): ?CarbonImmutable
    {
        if ($iso === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($iso);
        } catch (\Throwable) {
            // A malformed date just leaves posted_at unset.
            return null;
        }
    }

    /**
     * The channel @handle or name from oEmbed author_url (`youtube.com/@handle`),
     * falling back to author_name.
     *
     * @param  array<string, mixed>  $body
     */
    private function handleFromAuthorUrl(array $body): ?string
    {
        $authorUrl = $this->str($body['author_url'] ?? null) ?? '';
        if (preg_match('#/@([A-Za-z0-9._-]+)#', $authorUrl, $m) === 1) {
            return $m[1];
        }

        return $this->str($body['author_name'] ?? null);
    }
}
