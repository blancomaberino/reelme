<?php

namespace App\Services\Geo;

/**
 * Extended business fields for an already-resolved place (T-084), fetched on
 * demand by the "enrich as business" action via a {@see BusinessDetailProvider}.
 * Distinct from {@see GeocodeResult} (which resolves a location for the pipeline):
 * these are the curated contact/hours fields, pulled with a wider — and more
 * billable — provider field mask that the pipeline never uses.
 */
final readonly class BusinessDetails
{
    /**
     * @param  array<int|string, mixed>|null  $openingHours
     */
    public function __construct(
        public ?string $phone = null,
        public ?string $website = null,
        public ?array $openingHours = null,
        public ?float $rating = null,
        public ?int $ratingCount = null,
    ) {}

    /**
     * The non-empty curated-field patch these details contribute — only the
     * fields the enricher may write onto a place (rating/count live on Google's
     * own columns, refreshed elsewhere).
     *
     * @return array<string, mixed>
     */
    public function toPlacePatch(): array
    {
        return array_filter([
            'phone' => $this->phone,
            'website' => $this->website,
            'opening_hours_json' => $this->openingHours,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'website' => $this->website,
            'opening_hours' => $this->openingHours,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phone: $data['phone'] ?? null,
            website: $data['website'] ?? null,
            openingHours: $data['opening_hours'] ?? null,
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            ratingCount: isset($data['rating_count']) ? (int) $data['rating_count'] : null,
        );
    }
}
