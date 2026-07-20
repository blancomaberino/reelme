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
 */
class InstagramGraphAdapter implements SourceAdapter
{
    use FetchesOEmbed;

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
            externalId: $this->externalId($canonicalUrl),
            url: $canonicalUrl,
            caption: $this->str($media['caption'] ?? null),
            // The linked account IS the sharer — but the post's real author is
            // the poster, so only trust the handle for provenance, not naming.
            authorHandle: $account?->handle,
            postedAt: $this->timestamp($media['timestamp'] ?? null),
            media: $this->descriptors($media),
            raw: ['source' => 'instagram_graph'] + $media,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        $token = $this->usableToken($account);
        if ($token === null) {
            return new MediaFetchResult;
        }

        $media = $this->findMedia($post->url, $token);
        $url = $media !== null ? $this->str($media['media_url'] ?? null) : null;

        // Only downloadable video bytes feed DownloadMedia; images go through the
        // separate image-resolver chain (keyframes), not here.
        if ($url === null || strtoupper((string) ($media['media_type'] ?? '')) !== 'VIDEO') {
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
     * Fetch the linked user's recent media and return the entry whose permalink
     * matches the shared URL's shortcode, or null when none matches.
     *
     * @return array<string, mixed>|null
     */
    private function findMedia(string $canonicalUrl, string $token): ?array
    {
        $target = $this->shortcode($canonicalUrl);
        if ($target === null) {
            return null; // a non-permalink URL can't be matched
        }

        foreach ($this->requestMedia($token) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $permalink = $this->str($item['permalink'] ?? null);
            if ($permalink !== null && $this->shortcode($permalink) === $target) {
                /** @var array<string, mixed> $item */
                return $item;
            }
        }

        return null;
    }

    /**
     * GET the linked user's media list. The token is a query VALUE against a
     * fixed, hardcoded Graph host (never the request host) — the SSRF-relevant
     * boundary. Failures map to the §8 taxonomy: 401/403 (token rejected) →
     * auth-required; 429 → retryable; any other non-2xx / connection error →
     * FetchFailed (advance the chain, never crash).
     *
     * @return array<int, mixed>
     */
    private function requestMedia(string $token): array
    {
        try {
            $response = Http::timeout($this->timeout)->get($this->graphBase.'/me/media', [
                'fields' => 'id,caption,media_type,media_url,permalink,timestamp',
                'access_token' => $token,
            ]);
        } catch (ConnectionException) {
            throw new FetchFailed('Instagram Graph request failed.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new PostUnavailable('Instagram token was rejected.', requiresAuth: true);
        }
        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            throw new FetchFailed('Instagram Graph rate limited.', retryAfter: $retryAfter > 0 ? $retryAfter : 60);
        }
        if ($response->failed()) {
            throw new FetchFailed('Instagram Graph returned '.$response->status().'.');
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
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

        $type = strtoupper((string) ($media['media_type'] ?? '')) === 'VIDEO' ? 'video' : 'image';

        return [new MediaDescriptor(type: $type, url: $url)];
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

    /** The shortcode from /p/, /reel/, /reels/, /tv/ (else null — nothing to match on). */
    private function shortcode(string $url): ?string
    {
        return preg_match('#/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $url, $m) === 1 ? $m[1] : null;
    }

    /** The shortcode, else a stable hash of the URL — mirrors InstagramAdapter. */
    private function externalId(string $url): string
    {
        return $this->shortcode($url) ?? substr(sha1($url), 0, 24);
    }
}
