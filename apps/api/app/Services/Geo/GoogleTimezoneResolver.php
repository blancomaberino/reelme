<?php

namespace App\Services\Geo;

use App\Support\OpeningSchedule;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * {@see TimezoneResolver} backed by the Google Time Zone API (T-155).
 *
 * **This is a separate billed SKU from Places** — it is not part of the Place
 * Details response at any field mask, so every resolve is its own request.
 * Two things keep the bill flat:
 *
 * - **Coordinates are rounded to ~1 km before they become the cache key.** A
 *   timezone boundary is not a street, so a 3-decimal grid loses nothing real
 *   and turns every venue in a neighborhood into one cache entry.
 * - **The answer is cached for a year.** A restaurant does not move, and a zone
 *   *id* is stable even when its DST rules change — the rules live in PHP's tz
 *   database, which is what {@see OpeningSchedule} reads. Caching
 *   the id is therefore not caching an offset.
 *
 * Failure is always `null`, never an exception and never a fallback zone: the
 * caller treats null as "no status cue", which is the honest answer. An enrich
 * run must not fail because a timezone lookup did.
 */
class GoogleTimezoneResolver implements TimezoneResolver
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/timezone';

    /** ~111 m per 0.001°, so a shared key covers a block, not a country. */
    private const KEY_PRECISION = 3;

    private const CACHE_DAYS = 365;

    public function resolve(float $lat, float $lng): ?string
    {
        if ($this->apiKey() === '' || abs($lat) > 90.0 || abs($lng) > 180.0) {
            return null;
        }

        $key = sprintf(
            'geo:timezone:%s,%s',
            number_format($lat, self::KEY_PRECISION, '.', ''),
            number_format($lng, self::KEY_PRECISION, '.', ''),
        );

        // Cache the miss too, as a sentinel: `Cache::remember` treats a null
        // return as a miss and would re-bill this lookup on every enrich run of
        // a place the API cannot place.
        $cached = Cache::remember($key, now()->addDays(self::CACHE_DAYS), function () use ($lat, $lng): array {
            return ['zone' => $this->fetch($lat, $lng)];
        });

        return $cached['zone'];
    }

    private function fetch(float $lat, float $lng): ?string
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)->timeout(10)->get('/json', [
                'location' => $lat.','.$lng,
                // The API requires an instant (a zone's OFFSET depends on it). We
                // only read `timeZoneId`, which does not — so any instant does, and
                // `now()` keeps the request honest rather than pinned to an epoch.
                'timestamp' => now()->getTimestamp(),
                'key' => $this->apiKey(),
            ]);
        } catch (ConnectionException) {
            // Never surface the message: Guzzle embeds the full request URL,
            // including `?key=<secret>`, which would then reach laravel.log
            // (the same reason GooglePlacesGeocoder::get() swallows it).
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if (($json['status'] ?? null) !== 'OK') {
            return null;
        }

        $zone = $json['timeZoneId'] ?? null;

        // Validated, not trusted. A third-party string reaches a column that the
        // status computation later feeds to DateTimeZone; anything PHP's own tz
        // database does not list is not a zone we can compute against.
        if (! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return $zone;
    }

    private function apiKey(): string
    {
        return trim((string) config('services.google_places.key', ''));
    }
}
