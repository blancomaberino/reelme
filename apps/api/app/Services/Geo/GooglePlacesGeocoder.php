<?php

namespace App\Services\Geo;

use App\Services\Geo\Exceptions\GeocodeFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Places (legacy) implementation of the Geocoder: Find Place from Text →
 * Place Details with a strict field mask (billing is per field tier, so the mask
 * is asserted in tests and never widened casually). Results — hits and misses —
 * are cached 30 days keyed by a normalized (name, city, country) triple, both as
 * a cost control and to bound latency.
 */
class GooglePlacesGeocoder implements Geocoder
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/place';

    // Minimal Place Details field list — widening this raises the billed SKU.
    private const DETAILS_FIELDS = 'place_id,name,formatted_address,address_component,geometry/location,type';

    private const CACHE_DAYS = 30;

    public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
    {
        $key = $this->cacheKey($name, $hints);

        // Cache the serialized result (or a miss sentinel), never null directly —
        // Cache::remember treats null as a miss and would re-hit Google each call.
        // Exceptions propagate out of the closure and are never cached.
        $payload = Cache::remember($key, now()->addDays(self::CACHE_DAYS), function () use ($name, $hints): array {
            $result = $this->lookup($name, $hints);

            return $result === null ? ['miss' => true] : ['miss' => false, 'result' => $result->toArray()];
        });

        return $payload['miss'] ? null : GeocodeResult::fromArray($payload['result']);
    }

    private function lookup(string $name, GeoHints $hints): ?GeocodeResult
    {
        $placeId = $this->findPlaceId($name, $hints);
        if ($placeId === null) {
            return null;
        }

        $details = $this->placeDetails($placeId, $hints);

        $formattedAddress = (string) ($details['formatted_address'] ?? '');

        return new GeocodeResult(
            googlePlaceId: (string) ($details['place_id'] ?? $placeId),
            canonicalName: (string) ($details['name'] ?? $name),
            formattedAddress: $formattedAddress,
            addressComponents: $details['address_components'] ?? [],
            lat: (float) ($details['geometry']['location']['lat'] ?? 0.0),
            lng: (float) ($details['geometry']['location']['lng'] ?? 0.0),
            types: $details['types'] ?? [],
            score: $this->score($name, (string) ($details['name'] ?? ''), $formattedAddress, $hints),
        );
    }

    private function findPlaceId(string $name, GeoHints $hints): ?string
    {
        $input = implode(' ', array_filter([$name, $hints->city, $hints->country]));

        $query = array_filter([
            'input' => $input,
            'inputtype' => 'textquery',
            'fields' => 'place_id',
            'language' => $hints->language,
            'locationbias' => $hints->hasBias() ? "point:{$hints->lat},{$hints->lng}" : null,
            'key' => $this->apiKey(),
        ], fn ($v) => $v !== null);

        $json = $this->get('/findplacefromtext/json', $query);

        $status = (string) ($json['status'] ?? '');
        if ($status === 'ZERO_RESULTS') {
            return null;
        }
        $this->assertOk($status, $json);

        $candidates = $json['candidates'] ?? [];
        if ($candidates === []) {
            return null;
        }

        return isset($candidates[0]['place_id']) ? (string) $candidates[0]['place_id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function placeDetails(string $placeId, GeoHints $hints): array
    {
        $query = array_filter([
            'place_id' => $placeId,
            'fields' => self::DETAILS_FIELDS,
            'language' => $hints->language,
            'key' => $this->apiKey(),
        ], fn ($v) => $v !== null);

        $json = $this->get('/details/json', $query);
        $this->assertOk((string) ($json['status'] ?? ''), $json);

        /** @var array<string, mixed> $result */
        $result = $json['result'] ?? [];

        return $result;
    }

    /**
     * Honest 0–1 confidence: name similarity to the query, boosted when the
     * result's formatted address contains the hinted locality. Pure and public so
     * T-023's dedup tests can target the heuristic directly.
     */
    public function score(string $query, string $resultName, string $formattedAddress, GeoHints $hints): float
    {
        similar_text(mb_strtolower($query), mb_strtolower($resultName), $percent);
        $score = $percent / 100;

        if ($hints->city !== null && $hints->city !== ''
            && str_contains(mb_strtolower($formattedAddress), mb_strtolower($hints->city))) {
            $score += 0.1;
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)->timeout(10)->get($path, $query);
        } catch (ConnectionException) {
            // Never surface the exception message or chain it as `previous`: Guzzle
            // embeds the full request URL — including the `?key=<secret>` query
            // param — which would then be written to laravel.log. Report the safe
            // path constant only.
            throw new GeocodeFailed("Google Places request to {$path} failed (connection error).");
        }

        if ($response->failed()) {
            throw new GeocodeFailed('Google Places returned HTTP '.$response->status());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function assertOk(string $status, array $json): void
    {
        if ($status !== 'OK') {
            $message = isset($json['error_message']) ? ': '.$json['error_message'] : '';

            throw new GeocodeFailed("Google Places status {$status}{$message}");
        }
    }

    private function cacheKey(string $name, GeoHints $hints): string
    {
        $triple = implode('|', [
            $this->normalize($name),
            $this->normalize((string) $hints->city),
            $this->normalize((string) $hints->country),
        ]);

        return 'geocode:'.sha1($triple);
    }

    /** Lowercase, unaccented, whitespace-collapsed so lookalike queries share a key. */
    private function normalize(string $value): string
    {
        $ascii = Str::ascii($value);
        $collapsed = preg_replace('/\s+/', ' ', $ascii) ?? $ascii;

        return trim(mb_strtolower($collapsed));
    }

    private function apiKey(): string
    {
        return (string) config('services.google_places.key');
    }
}
