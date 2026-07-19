<?php

namespace App\Services\Reviews\Trustpilot;

use App\Models\Place;
use App\Services\Places\GooglePlaceRefresher;
use App\Services\Reviews\Drivers\TrustpilotReviewSource;

/**
 * Refresh-or-keep for a place's cached Trustpilot summary (T-082) — the sibling
 * of {@see GooglePlaceRefresher}, run out of band by the
 * daily `reelmap:trustpilot:refresh-stale` sweep (never on the request path).
 *
 * Trustpilot is keyed on the business domain from `places.website`; a place
 * without one is skipped (no row → the driver omits it). Past the per-driver
 * refresh window the summary is re-fetched and upserted; a transient failure
 * keeps the existing (recent-enough) row rather than blanking the place.
 */
class TrustpilotReviewRefresher
{
    public function __construct(private readonly TrustpilotClient $client) {}

    /** Max age (days) of a cached Trustpilot summary before it is re-fetched. */
    public function windowDays(?int $override = null): int
    {
        return max(1, $override ?? (int) config('reviews.sources.trustpilot.refresh_after_days', 7));
    }

    /** The registrable domain for a place, or null when it has no usable website. */
    public function domainFor(Place $place): ?string
    {
        return $this->client->domainFor((string) ($place->website ?? ''));
    }

    /**
     * True when the place should be (re)fetched: it has a website domain AND
     * either has no cached row yet or the cached row has aged past the window.
     */
    public function isStale(Place $place, ?int $windowDays = null): bool
    {
        if ($this->domainFor($place) === null) {
            return false;
        }

        $row = $place->externalReview(TrustpilotReviewSource::ID);

        return $row === null || $row->synced_at->lt(now()->subDays($this->windowDays($windowDays)));
    }

    /**
     * Fetch and upsert the place's Trustpilot summary. Returns 'refreshed' (row
     * written/updated), 'dropped' (the API confirmed no business → stale row
     * removed), or 'unchanged' (no domain, or a transient outage — the existing
     * row is kept, never blanked on a blip). The sweep's per-row entry point; the
     * client never throws.
     */
    public function refresh(Place $place): string
    {
        $domain = $this->domainFor($place);
        if ($domain === null) {
            return 'unchanged';
        }

        $fetch = $this->client->fetch($domain);
        $existing = $place->externalReview(TrustpilotReviewSource::ID);

        if ($fetch->status === 'unavailable') {
            // Transient (network/timeout/non-2xx) — keep any recent-enough row
            // rather than blanking the place on a passing outage.
            return 'unchanged';
        }

        if ($fetch->result === null) {
            // The API answered but nothing resolved for this domain → drop a stale
            // row so we don't keep showing a business Trustpilot no longer lists.
            if ($existing === null) {
                return 'unchanged';
            }
            $existing->delete();

            return 'dropped';
        }

        $result = $fetch->result;
        $place->externalReviews()->updateOrCreate(
            ['source' => TrustpilotReviewSource::ID],
            [
                'rating' => $result->rating !== null ? (string) $result->rating : null,
                'review_count' => $result->count,
                'url' => $result->url,
                'snippets_json' => array_map(fn ($s) => $s->toArray(), $result->snippets),
                'synced_at' => now(),
            ],
        );

        return 'refreshed';
    }
}
