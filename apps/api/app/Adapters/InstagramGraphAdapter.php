<?php

namespace App\Adapters;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaDescriptor;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\Support\FetchesOEmbed;
use App\Adapters\Support\InstagramUrl;
use App\Enums\MediaKind;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The authenticated Instagram strategy (T-015, chain slot 04 §2:
 * oEmbed → **Graph** → yt-dlp → manual). When the sharer has linked their
 * Instagram account, its OAuth token lets us read a post the keyless oEmbed
 * can't — a private/unlisted reel the sharer owns — via the Instagram Graph API
 * (Instagram API with Instagram Login).
 *
 * Ordering matters: oEmbed runs first and wins for public posts (no token
 * spent). This adapter only executes when oEmbed already failed. With no usable
 * token it maps to `fetch_auth_required` (advance the chain, but record the
 * "link your account" reason); with a token it fetches caption + media_url by
 * matching the shared permalink against the linked user's own media.
 *
 * fetchMetadata() persists the resolved media item (incl. `media_url`) into the
 * post's `raw`, so the later fetchMedia() reuses it instead of a second authed
 * round-trip — the download path never re-hits the scarce Graph quota.
 */
class InstagramGraphAdapter implements SourceAdapter
{
    use FetchesOEmbed;

    /** Marks a `raw` payload this adapter produced (read back by fetchMedia). */
    private const SOURCE = 'instagram_graph';

    /** Bound on `/me/media` pages walked to find an owned post (25/page ⇒ ~125). */
    private const MAX_PAGES = 5;

    public function __construct(
        private readonly string $graphBase = 'https://graph.instagram.com',
        private readonly int $timeout = 10,
    ) {}

    public function supports(string $canonicalUrl): bool
    {
        return $this->hostMatches($canonicalUrl, ['instagram.com']);
    }

    public function requiresAuth(): bool
    {
        return true;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        if (! $this->supports($canonicalUrl)) {
            throw new PostUnavailable('Unsupported Instagram URL.');
        }

        $token = $this->usableToken($account);
        if ($token === null) {
            // We only got here because oEmbed failed (private/unavailable) and
            // there is no linked token → surface the auth-required reason, then
            // advance the chain to yt-dlp/manual.
            throw new PostUnavailable('Instagram post requires a linked account.', requiresAuth: true);
        }

        $media = $this->findMedia($canonicalUrl, $token);
        if ($media === null) {
            // Valid token, but the post isn't in the linked user's media (not
            // theirs / deleted) — permanent, advance.
            throw new PostUnavailable('Post not found in the linked Instagram account.');
        }

        return new SourcePostData(
            platform: Platform::Instagram,
            externalId: InstagramUrl::externalId($canonicalUrl),
            url: $canonicalUrl,
            caption: $this->str($media['caption'] ?? null),
            // The linked account IS the sharer — trust the handle for provenance
            // only, never for naming the venue.
            authorHandle: $account?->handle,
            postedAt: $this->timestamp($media['timestamp'] ?? null),
            media: $this->descriptors($media),
            // The media_url rides in `raw` so fetchMedia() can reuse it.
            raw: ['source' => self::SOURCE] + $media,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // Reuse the media_url resolved (and persisted) by fetchMetadata — no
        // second Graph call. A post this adapter didn't resolve (raw.source !==
        // instagram_graph, e.g. a public oEmbed post) yields nothing and falls
        // straight through to yt-dlp.
        $media = $post->raw;
        if (($media['source'] ?? null) !== self::SOURCE) {
            return new MediaFetchResult;
        }

        $url = $this->str($media['media_url'] ?? null);
        if ($url === null || ! $this->isVideo($media)) {
            return new MediaFetchResult;
        }

        return new MediaFetchResult([
            new FetchedMedia(kind: MediaKind::Video, url: $url, mime: 'video/mp4'),
        ]);
    }

    /** The usable token from the DTO, or null when absent (the DTO already drops expired ones). */
    private function usableToken(?LinkedAccount $account): ?string
    {
        if ($account === null || $account->platform !== Platform::Instagram) {
            return null;
        }

        return $account->accessToken !== '' ? $account->accessToken : null;
    }

    /**
     * Walk the linked user's media (bounded pages) and return the entry whose
     * permalink matches the shared URL's shortcode, or null when none matches.
     *
     * @return array<string, mixed>|null
     */
    private function findMedia(string $canonicalUrl, string $token): ?array
    {
        $target = InstagramUrl::shortcode($canonicalUrl);
        if ($target === null) {
            return null; // a non-permalink URL can't be matched
        }

        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->requestMedia($token, $after);

            /** @var array<int, mixed> $data */
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            foreach ($data as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $permalink = $this->str($item['permalink'] ?? null);
                if ($permalink !== null && InstagramUrl::shortcode($permalink) === $target) {
                    /** @var array<string, mixed> $item */
                    return $item;
                }
            }

            // Paginate by the `after` CURSOR against our fixed host (never follow
            // the opaque `paging.next` URL — that would let the response steer the
            // request host). Stop when the API reports no further page.
            $after = $this->str(data_get($body, 'paging.cursors.after'));
            if ($after === null || ! isset($body['paging']['next'])) {
                break;
            }
        }

        return null;
    }

    /**
     * GET one page of the linked user's media. The token is a query VALUE against
     * the fixed, hardcoded Graph host (never the request host) — the SSRF-relevant
     * boundary. 401/403 (token rejected) → auth-required; everything else maps via
     * the shared oEmbed taxonomy (429 retryable, other non-2xx → FetchFailed).
     *
     * @return array<string, mixed>
     */
    private function requestMedia(string $token, ?string $after): array
    {
        $query = [
            'fields' => 'id,caption,media_type,media_url,permalink,timestamp',
            'access_token' => $token,
        ];
        if ($after !== null) {
            $query['after'] = $after;
        }

        try {
            $response = Http::timeout($this->timeout)->get($this->graphBase.'/me/media', $query);
        } catch (ConnectionException) {
            throw new FetchFailed('Instagram Graph request failed.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PostUnavailable('Instagram token was rejected.', requiresAuth: true);
        }

        // Shared taxonomy: 429 → retryable FetchFailed(Retry-After); 404/410 →
        // PostUnavailable; any other non-2xx → FetchFailed. Returns on 2xx.
        $this->guard($response);

        /** @var array<string, mixed> $body */
        $body = is_array($response->json()) ? $response->json() : [];

        return $body;
    }

    /**
     * Media descriptors advertised by the Graph payload (bytes fetched later).
     *
     * @param  array<string, mixed>  $media
     * @return array<int, MediaDescriptor>
     */
    private function descriptors(array $media): array
    {
        $url = $this->str($media['media_url'] ?? null);
        if ($url === null) {
            return [];
        }

        return [new MediaDescriptor(type: $this->isVideo($media) ? 'video' : 'image', url: $url)];
    }

    /**
     * Whether the Graph item is a video — the only media kind DownloadMedia
     * ingests (images feed the separate keyframe resolver chain).
     *
     * @param  array<string, mixed>  $media
     */
    private function isVideo(array $media): bool
    {
        return strtoupper((string) ($media['media_type'] ?? '')) === 'VIDEO';
    }

    /** Parse Instagram's ISO-8601 timestamp, tolerating a missing/garbage value. */
    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
