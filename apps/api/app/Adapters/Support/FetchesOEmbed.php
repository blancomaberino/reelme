<?php

namespace App\Adapters\Support;

use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared oEmbed plumbing for the keyless platform adapters (T-014): a
 * browser-UA'd, timeout-bounded client and a single place that maps transport
 * and HTTP failures to the adapter taxonomy (04 §8). Keeps XAdapter,
 * TikTokAdapter, and YouTubeAdapter free of duplicated Http/exception wiring.
 */
trait FetchesOEmbed
{
    /** A browser-UA'd, timeout-bounded HTTP client — some providers reject default agents. */
    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('ingestion.oembed.timeout', 10))
            ->withHeaders(['User-Agent' => (string) config('ingestion.oembed.user_agent')]);
    }

    /**
     * GET an oEmbed endpoint with the URL passed as a query param (never as the
     * request host — the SSRF-relevant boundary), returning the decoded body.
     * Failures map to the §8 taxonomy via guard(): 429 → retryable FetchFailed;
     * 401/403/404/410 → permanent PostUnavailable; any other non-2xx or a
     * connection error → FetchFailed (advance the chain, never crash).
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    protected function getOEmbed(string $endpoint, array $query): array
    {
        try {
            $response = $this->http()->get($endpoint, $query);
        } catch (ConnectionException) {
            // Never interpolate the URL/message (log-leak policy) — transient.
            throw new FetchFailed('oEmbed request failed.');
        }

        $this->guard($response);

        /** @var array<string, mixed> $body */
        $body = is_array($response->json()) ? $response->json() : [];

        return $body;
    }

    /**
     * Map an oEmbed/API HTTP status to the adapter failure taxonomy. Returns
     * cleanly on a 2xx so the caller can parse the body.
     */
    protected function guard(Response $response): void
    {
        if ($response->status() === 429) {
            // Transient rate-limit — release + retry with backoff, don't degrade to manual.
            $retryAfter = (int) $response->header('Retry-After');
            throw new FetchFailed('oEmbed rate limited.', retryAfter: $retryAfter > 0 ? $retryAfter : 60);
        }
        if (in_array($response->status(), [401, 403, 404, 410], true)) {
            throw new PostUnavailable('Post is unavailable or private.');
        }
        if ($response->failed()) {
            throw new FetchFailed('oEmbed returned '.$response->status().'.');
        }
    }

    /**
     * Suffix-anchored host match (SSRF-relevant platform classification): true
     * when the URL's host is exactly one of $domains or a dot-subdomain of one —
     * never a bare substring, so `x.com.evil.com` is not classified as `x.com`.
     *
     * @param  array<int, string>  $domains
     */
    protected function hostMatches(string $url, array $domains): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /** Coerce a possibly-missing/non-scalar oEmbed value to a trimmed non-empty string, else null. */
    protected function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
