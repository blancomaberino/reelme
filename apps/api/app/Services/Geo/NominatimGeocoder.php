<?php

namespace App\Services\Geo;

use App\Services\Geo\Exceptions\GeocodeFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Keyless geocoder backed by OpenStreetMap Nominatim — the zero-config default so
 * the pipeline resolves real place names without a Google Places key (demo / dev).
 * Maps Nominatim results onto the same GeocodeResult contract as the Google
 * adapter; a transient network error surfaces as GeocodeFailed (retryable), a
 * genuine miss returns null. Results cache 30 days keyed by (name, city, country).
 */
class NominatimGeocoder implements Geocoder
{
    public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
    {
        $query = $this->query($name, $hints);
        $cacheKey = 'geo:nominatim:'.md5($query);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached['miss'] ?? false ? null : GeocodeResult::fromArray($cached);
        }

        $result = $this->lookup($query, $hints);

        Cache::put($cacheKey, $result?->toArray() ?? ['miss' => true], now()->addDays(30));

        return $result;
    }

    private function lookup(string $query, GeoHints $hints): ?GeocodeResult
    {
        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('geo.nominatim.user_agent')])
                ->timeout((int) config('geo.nominatim.timeout', 10))
                ->get((string) config('geo.nominatim.url').'/search', array_filter([
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 1,
                    'accept-language' => $hints->language,
                ], fn ($v) => $v !== null && $v !== ''));
        } catch (ConnectionException) {
            // Never interpolate the message/URL — it can carry query text; treat as
            // a retryable transient error.
            throw new GeocodeFailed('Nominatim request failed.');
        }

        if ($response->failed()) {
            throw new GeocodeFailed('Nominatim returned '.$response->status().'.');
        }

        /** @var array<int, array<string, mixed>> $body */
        $body = $response->json();
        $top = $body[0] ?? null;
        if (! is_array($top) || ! isset($top['lat'], $top['lon'])) {
            return null;
        }

        return $this->toResult($top);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toResult(array $row): GeocodeResult
    {
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];
        $displayName = (string) ($row['display_name'] ?? '');
        $name = (string) ($row['name'] ?? '') ?: explode(',', $displayName)[0];

        return new GeocodeResult(
            googlePlaceId: 'osm:'.($row['osm_type'] ?? 'x').':'.($row['osm_id'] ?? '0'),
            canonicalName: $name,
            formattedAddress: $displayName,
            addressComponents: $this->components($address),
            lat: (float) $row['lat'],
            lng: (float) $row['lon'],
            types: array_filter([(string) ($row['category'] ?? ''), (string) ($row['type'] ?? '')]),
            // Nominatim `importance` is 0–1 but often low; floor it so a confident
            // single hit clears the resolver's 0.5 gate.
            score: max(0.6, (float) ($row['importance'] ?? 0.6)),
        );
    }

    /**
     * Map Nominatim's address object onto the Google-style component list the
     * resolver reads (country short_name, locality, route, region, postal_code).
     *
     * @param  array<string, mixed>  $address
     * @return list<array<string, mixed>>
     */
    private function components(array $address): array
    {
        $components = [];

        if (isset($address['country_code'])) {
            $components[] = [
                'long_name' => (string) ($address['country'] ?? ''),
                'short_name' => strtoupper((string) $address['country_code']),
                'types' => ['country', 'political'],
            ];
        }

        $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null;
        if ($city !== null) {
            $components[] = ['long_name' => (string) $city, 'short_name' => (string) $city, 'types' => ['locality']];
        }
        if (isset($address['road'])) {
            $components[] = ['long_name' => (string) $address['road'], 'short_name' => (string) $address['road'], 'types' => ['route']];
        }
        if (isset($address['state'])) {
            $components[] = ['long_name' => (string) $address['state'], 'short_name' => (string) $address['state'], 'types' => ['administrative_area_level_1']];
        }
        if (isset($address['postcode'])) {
            $components[] = ['long_name' => (string) $address['postcode'], 'short_name' => (string) $address['postcode'], 'types' => ['postal_code']];
        }

        return $components;
    }

    private function query(string $name, GeoHints $hints): string
    {
        return implode(', ', array_filter([
            trim($name),
            $hints->city,
            $hints->region,
            $hints->country,
        ], fn ($v) => $v !== null && trim((string) $v) !== ''));
    }
}
