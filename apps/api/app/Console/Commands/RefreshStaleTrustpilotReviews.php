<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Reviews\Trustpilot\TrustpilotReviewRefresher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Out-of-band Trustpilot cache sweep (T-082) — the sibling of
 * `reelmap:google:refresh-stale`. For every publicly-visible place carrying a
 * website (a resolvable Trustpilot domain) whose cached summary is missing or
 * past the refresh window, re-fetch and upsert through the shared
 * {@see TrustpilotReviewRefresher}. Config-gated: a no-op when the Trustpilot
 * source is disabled or unkeyed. Scheduled daily.
 */
class RefreshStaleTrustpilotReviews extends Command
{
    protected $signature = 'reelmap:trustpilot:refresh-stale {--days= : Max cache age in days before refresh (default: reviews.sources.trustpilot.refresh_after_days)}';

    protected $description = 'Refresh cached Trustpilot review summaries older than the per-driver window';

    public function handle(TrustpilotReviewRefresher $refresher): int
    {
        if (! config('reviews.sources.trustpilot.enabled') || ! filled(config('reviews.sources.trustpilot.api_key'))) {
            $this->components->info('Trustpilot source disabled or unkeyed — nothing to refresh.');

            return self::SUCCESS;
        }

        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $refreshed = 0;
        $dropped = 0;

        Place::query()
            ->publiclyVisible()
            ->whereNotNull('website')
            ->where('website', '!=', '')
            ->with('externalReviews')
            ->chunkById(100, function ($places) use ($refresher, $days, &$refreshed, &$dropped) {
                foreach ($places as $place) {
                    // Per-row isolation: one failure (staleness check included)
                    // must never abort the sweep and strand the remaining places.
                    try {
                        if (! $refresher->isStale($place, $days)) {
                            continue;
                        }
                        match ($refresher->refresh($place)) {
                            'refreshed' => $refreshed++,
                            'dropped' => $dropped++,
                            default => null,
                        };
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->components->info("Trustpilot review cache: {$refreshed} refreshed, {$dropped} dropped.");

        return self::SUCCESS;
    }
}
