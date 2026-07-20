<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\Support\FetchesOEmbed;
use App\Enums\Platform;

/**
 * Public X / Twitter post metadata via the keyless publish.x.com oEmbed endpoint
 * (T-014, chain per 01 §5: oEmbed → yt-dlp → manual). This adapter only turns a
 * status URL into caption + author for the text path; video bytes are the
 * yt-dlp step's job and image-only posts stay caption-only.
 *
 * The X API v2 user-token step (private/authed posts) named in the spec chain is
 * auth-dependent and deferred to platform-account linking (T-015-style). The
 * chain — where an authed adapter slots in ahead of yt-dlp — is that seam.
 */
class XAdapter implements SourceAdapter
{
    use FetchesOEmbed;

    /** Post hosts X exposes; t.co shortlinks are expanded upstream (T-016). */
    private const HOSTS = ['x.com', 'twitter.com'];

    public function supports(string $canonicalUrl): bool
    {
        // Kill switch is enforced in AdapterRegistry (skips the whole chain).
        return $this->statusId($canonicalUrl) !== null;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        $id = $this->statusId($canonicalUrl);
        if ($id === null) {
            // Never interpolate the URL into the message (log-leak policy).
            throw new PostUnavailable('Unsupported X URL.');
        }

        // publish.x.com is unauthenticated but unstable (07 R-01) — any non-200
        // maps to FetchFailed/PostUnavailable inside getOEmbed(), never a crash.
        $body = $this->getOEmbed('https://publish.x.com/oembed', [
            'url' => $canonicalUrl,
            'omit_script' => '1',
        ]);

        return new SourcePostData(
            platform: Platform::X,
            externalId: $id,
            url: $canonicalUrl,
            // X wraps the tweet text in an HTML blockquote — pull the <p> text and
            // decode entities; never persist HTML into source_posts.caption.
            caption: $this->captionFromHtml($this->str($body['html'] ?? null)),
            authorHandle: $this->handle($body),
            authorDisplayName: $this->str($body['author_name'] ?? null),
            // X oEmbed carries no timestamp — posted_at stays null.
            raw: ['source' => 'x-oembed'] + $body,
        );
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        // Video bytes come from the yt-dlp step next in the chain; oEmbed exposes
        // no downloadable media URL (only an embed iframe).
        return new MediaFetchResult;
    }

    /** The status id from `x.com|twitter.com/{user}/status(es)/{id}`, else null. */
    private function statusId(string $url): ?string
    {
        if (! $this->hostMatches($url, self::HOSTS)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#/status(?:es)?/(\d+)#', $path, $m) === 1 ? $m[1] : null;
    }

    /**
     * Tweet text from the oEmbed HTML: the blockquote's first <p> holds the text
     * (the trailing "— Author (@handle) Date" attribution sits OUTSIDE the <p>,
     * so scoping to it drops that noise). <br> → newline preserves multi-line
     * tweets; then strip tags + decode entities.
     */
    private function captionFromHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $inner = preg_match('#<p[^>]*>(.*?)</p>#is', $html, $m) === 1 ? $m[1] : $html;
        $inner = preg_replace('#<br\s*/?>#i', "\n", $inner) ?? $inner;
        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    /**
     * The @handle from author_url (`x.com/{handle}` or `twitter.com/{handle}`).
     *
     * @param  array<string, mixed>  $body
     */
    private function handle(array $body): ?string
    {
        $authorUrl = $this->str($body['author_url'] ?? null) ?? '';

        return preg_match('#(?:x\.com|twitter\.com)/([A-Za-z0-9_]+)#', $authorUrl, $m) === 1
            ? $m[1]
            : null;
    }
}
