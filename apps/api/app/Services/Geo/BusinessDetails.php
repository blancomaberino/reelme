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
     * @param  list<string>|null  $openingHours  Human-readable opening-hour LINES (T-128) — the `string[]` the place contract pins and the client renders verbatim. Never Google's `{periods, weekday_text}` object; run untrusted input through {@see hourLines()} first.
     * @param  list<array{url: string, attribution: ?string}>  $images  Google Places photos (T-099), owner-attribution ranking left to the enricher. Resolved, key-free URLs only.
     */
    public function __construct(
        public ?string $phone = null,
        public ?string $website = null,
        public ?array $openingHours = null,
        public ?float $rating = null,
        public ?int $ratingCount = null,
        public array $images = [],
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
            'images' => $this->images,
        ];
    }

    /**
     * Coerce an untrusted opening-hours value to the contract's flat list of
     * strings, or null when nothing usable survives.
     *
     * The one place that decision is made, so the geocoder and the cache
     * rehydrate identically. Non-strings and blanks are dropped rather than
     * stringified: a nested object rendered as "Array" is worse on a menu screen
     * than an absent line. Empty collapses to NULL, not `[]`, so
     * {@see toPlacePatch()} reads it as "the provider said nothing" and leaves
     * better hours already on the place alone.
     *
     * @return list<string>|null
     */
    public static function hourLines(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $lines = [];
        foreach ($value as $line) {
            if (is_string($line) && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        return $lines === [] ? null : $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phone: $data['phone'] ?? null,
            website: $data['website'] ?? null,
            // Normalized, not trusted: `fromArray()` rehydrates a CACHED payload,
            // so it can be handed a value written by an older, laxer path. The
            // column is typed `string[]` for the client (T-128).
            openingHours: self::hourLines($data['opening_hours'] ?? null),
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            ratingCount: isset($data['rating_count']) ? (int) $data['rating_count'] : null,
            images: is_array($data['images'] ?? null) ? $data['images'] : [],
        );
    }
}
