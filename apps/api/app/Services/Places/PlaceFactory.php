<?php

namespace App\Services\Places;

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Services\Geo\GeocodeResult;

/**
 * Builds a new pending {@see Place} from a resolved geocode or an IG-profile
 * location (T-095, split out of PlaceResolver). Every field sourced from the LLM
 * extraction is untrusted, so it's clamped/truncated to the column limits here —
 * a bad value parks the share via review, never a QueryException that burns the
 * job's retries as `resolve_conflict`.
 */
class PlaceFactory
{
    /**
     * Create a pending pin from an accepted geocode (has a google_place_id).
     *
     * @param  array<string, mixed>  $place  the extracted place object
     */
    public function create(GeocodeResult $geo, array $place): Place
    {
        $components = $geo->addressComponents;
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $cuisines = is_array($place['cuisines'] ?? null) ? $place['cuisines'] : [];

        $model = new Place([
            'name' => $this->truncate($geo->canonicalName !== '' ? $geo->canonicalName : (string) ($place['name'] ?? 'Unknown'), 255),
            'address_line1' => $this->truncate($this->component($components, 'route') ?? ($address['street'] ?? null), 255),
            'city' => $this->truncate($this->component($components, 'locality') ?? ($address['city'] ?? null), 120),
            'region' => $this->truncate($this->component($components, 'administrative_area_level_1') ?? ($address['region'] ?? null), 120),
            'postal_code' => $this->truncate($this->component($components, 'postal_code') ?? ($address['postal_code'] ?? null), 24),
            'country_code' => $this->countryCode($geo, $address),
            'google_place_id' => $geo->googlePlaceId,
            'cuisine_primary' => $this->truncate($cuisines[0] ?? null, 64),
            'price_range' => $this->priceRange($place['price_range'] ?? null),
            'phone' => $this->truncate($place['phone'] ?? null, 32),
            'website' => $this->truncate($place['website'] ?? null, 2048),
            'google_rating' => $geo->rating,
            'google_rating_count' => $geo->ratingCount,
            // NULL (not '[]') when no snippets came back — the ToS refresh
            // sweep keys on whereNotNull and must not treat "rating, no
            // snippets" as forever-stale cached content.
            'google_reviews_json' => $geo->reviews !== [] ? $geo->reviews : null,
            // ToS clock: cached Places review content must be refreshed or
            // dropped ~30 days after capture (reelmap:google:refresh-stale).
            'google_reviews_synced_at' => $geo->reviews !== [] ? now() : null,
            'status' => PlaceStatus::Pending,
        ]);
        $model->setPoint($geo->lat, $geo->lng);
        $model->save();
        $model->refresh();

        return $model;
    }

    /**
     * Create a pending pin from an IG profile's raw business-address coordinates
     * (T-075) — no google_place_id (the geocoder couldn't resolve it), so it lands
     * for review/enrichment. Extraction fields are still clamped to the column
     * limits (untrusted), with the profile's structured address preferred.
     *
     * @param  array<string, mixed>  $place
     */
    public function createFromProfile(string $name, array $place, ProfileLocation $location): Place
    {
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $cuisines = is_array($place['cuisines'] ?? null) ? $place['cuisines'] : [];

        $model = new Place([
            'name' => $this->truncate($name, 255),
            'address_line1' => $this->truncate($location->street ?? ($address['street'] ?? null), 255),
            'city' => $this->truncate($location->city ?? ($address['city'] ?? null), 120),
            'region' => $this->truncate($location->region ?? ($address['region'] ?? null), 120),
            'postal_code' => $this->truncate($location->postalCode ?? ($address['postal_code'] ?? null), 24),
            'country_code' => $this->countryFromAddress($address),
            'google_place_id' => null,
            'cuisine_primary' => $this->truncate($cuisines[0] ?? null, 64),
            'price_range' => $this->priceRange($place['price_range'] ?? null),
            'phone' => $this->truncate($place['phone'] ?? null, 32),
            'website' => $this->truncate($place['website'] ?? null, 2048),
            'status' => PlaceStatus::Pending,
        ]);
        $model->setPoint((float) $location->lat, (float) $location->lng);
        $model->save();
        $model->refresh();

        return $model;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function component(array $components, string $type): ?string
    {
        foreach ($components as $component) {
            if (in_array($type, $component['types'] ?? [], true)) {
                $value = $component['long_name'] ?? $component['short_name'] ?? null;

                return $value !== null ? (string) $value : null;
            }
        }

        return null;
    }

    /** Clamp an extracted price band to the 1–4 CHECK, else null. */
    private function priceRange(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return ($int >= 1 && $int <= 4) ? $int : null;
    }

    private function truncate(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function countryCode(GeocodeResult $geo, array $address): string
    {
        foreach ($geo->addressComponents as $component) {
            if (in_array('country', $component['types'] ?? [], true)) {
                $short = strtoupper((string) ($component['short_name'] ?? ''));
                if (strlen($short) === 2) {
                    return $short;
                }
            }
        }

        return $this->countryFromAddress($address);
    }

    /**
     * A 2-letter country code from the extraction address alone (no geocoder
     * components), else the 'XX' unknown sentinel. Used by the IG-profile-coords
     * path, which has no GeocodeResult.
     *
     * @param  array<string, mixed>  $address
     */
    private function countryFromAddress(array $address): string
    {
        $extraction = strtoupper(trim((string) ($address['country'] ?? '')));

        return strlen($extraction) === 2 ? $extraction : 'XX';
    }
}
