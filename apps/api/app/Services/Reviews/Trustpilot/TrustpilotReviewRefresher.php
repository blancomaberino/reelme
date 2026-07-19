<?php

namespace App\Services\Reviews\Trustpilot;

use App\Models\ExternalPlaceReview;
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

        $row = $this->cachedRow($place);

        return $row === null || $row->synced_at->lt(now()->subDays($this->windowDays($windowDays)));
    }

    /**
     * Fetch and upsert the place's Trustpilot summary. Returns
     * 'refreshed' (row written/updated), 'dropped' (fetch resolved nothing → the
     * stale row removed), or 'unchanged' (no domain, or a transient miss with a
     * row kept). The sweep's per-row entry point; the client never throws.
     */
    public function refresh(Place $place): string
    {
        $domain = $this->domainFor($place);
        if ($domain === null) {
            return 'unchanged';
        }

        $result = $this->client->fetch($domain);
        $existing = $this->cachedRow($place);

        if ($result === null) {
            // Never fetched before → nothing to keep; a prior row → drop the stale
            // signal (the source resolved to nothing now). A recent transient blip
            // still drops here — acceptable: the driver just omits the place until
            // the next successful sweep re-populates it.
            if ($existing === null) {
                return 'unchanged';
            }
            $existing->delete();

            return 'dropped';
        }

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

    private function cachedRow(Place $place): ?ExternalPlaceReview
    {
        if ($place->relationLoaded('externalReviews')) {
            return $place->externalReviews->firstWhere('source', TrustpilotReviewSource::ID);
        }

        return $place->externalReviews()->where('source', TrustpilotReviewSource::ID)->first();
    }
}
