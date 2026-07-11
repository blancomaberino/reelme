<?php

namespace App\Services\Geo;

/**
 * A resolved place from the geocoder. `googlePlaceId` is the primary dedup key
 * in ResolvePlace (T-023) and the only Places field we may store indefinitely
 * per Google's ToS; `score` (0–1) is the geocoder's honest confidence, gated
 * downstream (score < 0.5 → review) but never here.
 */
final readonly class GeocodeResult
{
    /**
     * @param  array<int, array<string, mixed>>  $addressComponents
     * @param  list<string>  $types
     * @param  list<array<string, mixed>>  $reviews
     */
    public function __construct(
        public string $googlePlaceId,
        public string $canonicalName,
        public string $formattedAddress,
        public array $addressComponents,
        public float $lat,
        public float $lng,
        public array $types,
        public float $score,
        public ?float $rating = null,
        public ?int $ratingCount = null,
        public array $reviews = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'google_place_id' => $this->googlePlaceId,
            'canonical_name' => $this->canonicalName,
            'formatted_address' => $this->formattedAddress,
            'address_components' => $this->addressComponents,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'types' => $this->types,
            'score' => $this->score,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
            'reviews' => $this->reviews,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            googlePlaceId: (string) $data['google_place_id'],
            canonicalName: (string) $data['canonical_name'],
            formattedAddress: (string) $data['formatted_address'],
            addressComponents: $data['address_components'] ?? [],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            types: $data['types'] ?? [],
            score: (float) $data['score'],
            rating: $data['rating'] ?? null,
            ratingCount: $data['rating_count'] ?? null,
            reviews: $data['reviews'] ?? [],
        );
    }
}
