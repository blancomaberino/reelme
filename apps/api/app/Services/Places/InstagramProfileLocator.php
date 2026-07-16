<?php

namespace App\Services\Places;

use App\Services\Media\Instagram\InstagramWebClient;

/**
 * Mines a venue's Instagram profile for a location (T-075) when the caption
 * named it only by an `@handle` and the geocoder found nothing. A multi-signal
 * fallback, NOT a magic lookup — value depends on the account:
 *
 *  1. `business_address_json` (street/city/zip + lat/lng) → direct coords, best.
 *  2. `biography` (a 📍/address line) → a locality to enrich the geocode query.
 *  3. `full_name` → the real venue name (upgrades a bare `@handle`).
 *
 * Never throws (a dead/private profile just yields null → the caller keeps its
 * `geocode_failed`). Results are cached per handle for this locator instance so a
 * roundup mentioning the same handle twice fetches once.
 */
class InstagramProfileLocator
{
    /** @var array<string, ProfileLocation|null> */
    private array $cache = [];

    public function __construct(private readonly InstagramWebClient $client) {}

    /** Resolve a handle (with or without `@`) to a profile location, or null. */
    public function locate(string $handle): ?ProfileLocation
    {
        $handle = strtolower(ltrim(trim($handle), '@'));
        if ($handle === '') {
            return null;
        }

        return $this->cache[$handle] ??= $this->fetch($handle);
    }

    private function fetch(string $handle): ?ProfileLocation
    {
        $user = $this->client->profile($handle);
        if ($user === null) {
            return null;
        }

        $name = $this->trimOrNull(is_string($user['full_name'] ?? null) ? $user['full_name'] : null);
        $business = $this->businessAddress($user);
        $bioLocality = $this->bioLocality(is_string($user['biography'] ?? null) ? $user['biography'] : '');

        $location = new ProfileLocation(
            name: $name,
            street: $business['street'],
            // Prefer the structured city; fall back to a 📍 bio locality.
            city: $business['city'] ?? $bioLocality,
            region: $business['region'],
            postalCode: $business['zip'],
            lat: $business['lat'],
            lng: $business['lng'],
        );

        // Nothing usable — no name, no locality, no coords → treat as a miss so
        // the caller keeps its honest geocode_failed rather than an empty pin.
        if ($location->name === null && ! $location->hasLocality() && ! $location->hasCoordinates()) {
            return null;
        }

        return $location;
    }

    /**
     * Parse `business_address_json` — Instagram returns it as a JSON *string*
     * (occasionally an array). Empty on a professional account that never set an
     * address, so every part is nullable.
     *
     * @param  array<string, mixed>  $user
     * @return array{street: ?string, city: ?string, region: ?string, zip: ?string, lat: ?float, lng: ?float}
     */
    private function businessAddress(array $user): array
    {
        $raw = $user['business_address_json'] ?? null;
        $addr = is_string($raw) ? json_decode($raw, true) : $raw;
        $addr = is_array($addr) ? $addr : [];

        return [
            'street' => $this->trimOrNull(is_string($addr['street_address'] ?? null) ? $addr['street_address'] : null),
            'city' => $this->trimOrNull(is_string($addr['city_name'] ?? null) ? $addr['city_name'] : null),
            'region' => $this->trimOrNull(is_string($addr['region_name'] ?? null) ? $addr['region_name'] : null),
            'zip' => $this->trimOrNull(is_string($addr['zip_code'] ?? null) ? $addr['zip_code'] : null),
            'lat' => $this->coord($addr['latitude'] ?? null),
            'lng' => $this->coord($addr['longitude'] ?? null),
        ];
    }

    /**
     * The locality after a 📍 pin in the bio (e.g. "…🥩 asado 📍Barros Blancos 🛵"
     * → "Barros Blancos"): text after the pin, stopped at the next emoji or line
     * break, trimmed and length-capped. Null when there is no pin.
     */
    private function bioLocality(string $bio): ?string
    {
        if (preg_match('/📍\s*(.+)/u', $bio, $m) !== 1) {
            return null;
        }

        // Cut at the next emoji/pictograph or newline so trailing "🛵 Delivery"
        // does not leak into the locality.
        $text = (string) preg_split('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\n\r]/u', $m[1])[0];

        return $this->trimOrNull(mb_substr(trim($text), 0, 120));
    }

    private function coord(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $float = (float) $value;

        // A 0/0 "null island" pair is IG's unset default, not a real venue.
        return $float === 0.0 ? null : $float;
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
