<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Places\GooglePlaceRefresher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Google-ToS compliance (T-059): Places review content may not be cached beyond
 * ~30 days. For every place whose cached snippets are stale, refresh-or-drop
 * through the shared {@see GooglePlaceRefresher} — the SAME logic the on-demand
 * re-share path uses (T-080), so both surfaces agree on what "stale" and
 * "refresh-or-drop" mean. Scheduled daily.
 */
class RefreshStaleGoogleReviews extends Command
{
    protected $signature = 'reelmap:google:refresh-stale {--days= : Max cache age in days before refresh-or-drop (default: places.google.refresh_after_days)}';

    protected $description = 'Refresh or drop cached Google review snippets older than the ToS window';

    public function handle(GooglePlaceRefresher $refresher): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $cutoff = now()->subDays($refresher->windowDays($days));
        $refreshed = 0;
        $dropped = 0;

        Place::query()
            ->whereNotNull('google_reviews_json')
            ->where(fn ($q) => $q
                ->whereNull('google_reviews_synced_at')
                ->orWhere('google_reviews_synced_at', '<', $cutoff))
            ->chunkById(100, function ($places) use ($refresher, &$refreshed, &$dropped) {
                foreach ($places as $place) {
                    // Per-row isolation: one bad row must never abort the run —
                    // every remaining stale place would keep its expired content.
                    try {
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

        $this->components->info("Google review cache: {$refreshed} refreshed, {$dropped} dropped.");

        return self::SUCCESS;
    }
}
