<?php

namespace App\Services\Reviews\Trustpilot;

use App\Services\Reviews\ReviewSnippet;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Trustpilot Business Units API client (T-082): resolve a business by its domain
 * → TrustScore + review count + a few snippet excerpts. Config-gated
 * (`reviews.sources.trustpilot`); a place with no resolvable business simply
 * yields null.
 *
 * ToS/SSRF: it only ever connects to the single configured `base_url` host
 * (Trustpilot's API), never to a place-derived URL — the domain is passed as a
 * query *value*, not a request host — and it still vets that domain as a public
 * hostname first (no IPs/localhost). Redirects are disabled so a 30x can't
 * bounce the request to an internal address. It NEVER throws: any error (missing
 * key, network, malformed body) becomes an `unavailable` {@see TrustpilotFetch}
 * so one bad source degrades to the others.
 */
class TrustpilotClient
{
    /**
     * Fetch the summary for a business by its website/domain (e.g.
     * "https://joes.com" or "joes.com"). Never throws — a failure is reported as
     * `unavailable` (keep any cached row), a clean "no business" as `empty`
     * (drop the cached row), a hit as `resolved`.
     */
    public function fetch(string $domain): TrustpilotFetch
    {
        $domain = $this->domainFor($domain);
        if ($domain === null) {
            return TrustpilotFetch::empty(); // no resolvable business for this place
        }
        if (! $this->enabled()) {
            return TrustpilotFetch::unavailable(); // can't determine — leave cache as-is
        }

        try {
            $unit = $this->request()->get('/business-units/find', ['name' => $domain]);
            if (! $unit->successful() || ! is_array($unit->json())) {
                return TrustpilotFetch::unavailable(); // transient / non-2xx
            }
            $body = $unit->json();

            $businessId = $this->str($body['id'] ?? null);
            $rating = $this->score($body['score'] ?? null);
            $count = $this->reviewCount($body['numberOfReviews'] ?? null);
            if ($businessId === null && $rating === null && $count === 0) {
                return TrustpilotFetch::empty(); // API answered, nothing resolved
            }

            return TrustpilotFetch::resolved(new TrustpilotResult(
                rating: $rating,
                count: $count,
                url: 'https://www.trustpilot.com/review/'.$domain,
                snippets: $businessId !== null ? $this->snippets($businessId) : [],
            ));
        } catch (Throwable $e) {
            report($e);

            return TrustpilotFetch::unavailable();
        }
    }

    /**
     * Trustpilot's review total, from either `{total: N}` or a bare `N`. Guards
     * the int-cast: a non-numeric/array-without-`total` shape yields 0, never a
     * bogus `1` (PHP casts a non-empty array to 1).
     */
    private function reviewCount(mixed $numberOfReviews): int
    {
        $value = is_array($numberOfReviews) ? ($numberOfReviews['total'] ?? null) : $numberOfReviews;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Up to 5 normalized review excerpts for a business unit; [] on any hiccup.
     *
     * @return list<ReviewSnippet>
     */
    private function snippets(string $businessId): array
    {
        $response = $this->request()->get("/business-units/{$businessId}/reviews", ['perPage' => 5]);
        if (! $response->successful()) {
            return [];
        }

        $reviews = $response->json('reviews');
        if (! is_array($reviews)) {
            return [];
        }

        $snippets = [];
        foreach (array_slice($reviews, 0, 5) as $review) {
            if (! is_array($review)) {
                continue;
            }
            $snippets[] = ReviewSnippet::fromArray([
                'author' => $review['consumer']['displayName'] ?? null,
                'rating' => $review['stars'] ?? null,
                'text' => $review['text'] ?? $review['title'] ?? null,
            ]);
        }

        return $snippets;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('reviews.sources.trustpilot.base_url'), '/'))
            ->withHeaders(['apikey' => (string) config('reviews.sources.trustpilot.api_key')])
            ->timeout((int) config('reviews.sources.trustpilot.timeout', 10))
            // Vetted only this host — never follow a redirect to another (SSRF).
            ->withOptions(['allow_redirects' => false])
            ->acceptJson();
    }

    private function enabled(): bool
    {
        return (bool) config('reviews.sources.trustpilot.enabled')
            && filled(config('reviews.sources.trustpilot.api_key'));
    }

    /** Trustpilot's `score.trustScore` (0–5), clamped; null when absent. */
    private function score(mixed $score): ?float
    {
        $value = is_array($score) ? ($score['trustScore'] ?? $score['stars'] ?? null) : null;
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(5.0, (float) $value));
    }

    /**
     * Reduce a website value to a bare, public registrable host (no scheme, no
     * `www.`, no path). Rejects IP literals and unqualified/local names — the
     * domain becomes a Trustpilot query, and this keeps junk (and any SSRF-shaped
     * input) out. Returns null when nothing usable remains. Public so the
     * refresher can gate a place on "has a resolvable domain" without a fetch.
     */
    public function domainFor(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }

        // parse_url needs a scheme to populate `host`; add one if the value is bare.
        $host = parse_url(preg_match('#^https?://#i', $website) === 1 ? $website : 'https://'.$website, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(ltrim($host, '.'));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        // Must be a dotted public hostname — reject IPs, localhost, single labels.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (! str_contains($host, '.') || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        return $host;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
