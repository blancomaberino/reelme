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
    private const DETAILS_FIELDS = 'place_id,name,formatted_address,address_component,geometry/location,type,rating,user_ratings_total,reviews';

    private const CACHE_DAYS = 30;

    /** A name that leads the (usually longer) official name is a strong match. */
    private const LEADING_MATCH_SCORE = 0.85;

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
            rating: isset($details['rating']) ? (float) $details['rating'] : null,
            ratingCount: isset($details['user_ratings_total']) ? (int) $details['user_ratings_total'] : null,
            reviews: $this->reviews($details['reviews'] ?? []),
        );
    }

    /**
     * Normalize Google's Place Details `reviews` (up to 5) to our snippet shape.
     * Missing keys are guarded — Google occasionally omits `text` or timing fields.
     *
     * @return list<array<string, mixed>>
     */
    private function reviews(mixed $reviews): array
    {
        if (! is_array($reviews)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($reviews, 0, 5) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $photo = $review['profile_photo_url'] ?? null;

            $normalized[] = [
                'author' => (string) ($review['author_name'] ?? ''),
                'rating' => (int) ($review['rating'] ?? 0),
                'text' => (string) ($review['text'] ?? ''),
                'relative_time' => $review['relative_time_description'] ?? null,
                'time' => $review['time'] ?? null,
                // Google returns the reviewer's avatar in the same review object
                // (no field-mask/billing change); http(s) only, cached under the
                // same ToS refresh sweep as author/text.
                'profile_photo_url' => is_string($photo) && preg_match('#^https?://#i', $photo) === 1 ? $photo : null,
            ];
        }

        return $normalized;
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
     * result's formatted address confirms a hinted locality. Pure and public so
     * T-023's dedup tests can target the heuristic directly.
     *
     * A raw similar_text ratio punishes the common case where the official name
     * is longer than the name lifted from a caption — "Erevan" vs "Erevan Cocina
     * Armenia" scores only 0.44 and would fall below min_score, sending a
     * correctly-resolved place to manual review. So when the query leads the
     * official name, treat it as a strong match instead.
     */
    public function score(string $query, string $resultName, string $formattedAddress, GeoHints $hints): float
    {
        similar_text(mb_strtolower($query), mb_strtolower($resultName), $percent);
        $score = $percent / 100;

        if ($this->isLeadingNameMatch($query, $resultName)) {
            $score = max($score, self::LEADING_MATCH_SCORE);
        }

        // A single locality confirmation (+0.1) when the resolved address agrees
        // with any hinted city/region/country. Not cumulative — a result merely
        // sitting in the right country must not clear min_score on its own.
        foreach ([$hints->city, $hints->region, $hints->country] as $locality) {
            if ($locality !== null && $locality !== ''
                && str_contains(mb_strtolower($formattedAddress), mb_strtolower($locality))) {
                $score += 0.1;
                break;
            }
        }

        return round(max(0.0, min(1.0, $score)), 3);
    }

    /**
     * True when the shorter name's tokens are the *leading* tokens of the longer —
     * "Erevan" ⊂ "Erevan Cocina Armenia". Leading (not any-subset, not substring)
     * so a trailing descriptor ("Armenia") or an intra-word hit ("Bar" inside
     * "Barbagelata") does not earn the strong-match score.
     */
    private function isLeadingNameMatch(string $a, string $b): bool
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);

        if ($ta === [] || $tb === []) {
            return false;
        }

        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];

        return $short === array_slice($long, 0, count($short));
    }

    /**
     * Lowercased alphanumeric word tokens, accent- and punctuation-insensitive.
     *
     * @return list<string>
     */
    private function tokens(string $s): array
    {
        // Str::ascii folds accents (matching this class's cache-key normalize())
        // so "Erévan" and "Erevan" tokenize alike.
        $parts = preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii($s)), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
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
