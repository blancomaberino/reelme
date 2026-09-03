<?php

namespace App\Services\Geo;

use App\Support\OpeningHours;
use App\Support\OpeningSchedule;

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
     * @param  list<string>|null  $openingHours  Human-readable opening-hour LINES (T-128) — the `string[]` the place contract pins and the client renders verbatim. Never Google's `{periods, weekday_text}` object; run untrusted input through {@see OpeningHours::fromProvider()} first.
     * @param  list<array{open_day: int, open_time: string, close_day: ?int, close_time: ?string}>|null  $openingHoursPeriods  The SAME week as `$openingHours`, in machine-readable intervals (T-155). Two fields, never a union: the lines are rendered verbatim and the periods are never rendered at all — only computed from. {@see OpeningSchedule::fromProvider()}.
     * @param  list<array{url: string, attribution: ?string}>  $images  Google Places photos (T-099), owner-attribution ranking left to the enricher. Resolved, key-free URLs only.
     */
    public function __construct(
        public ?string $phone = null,
        public ?string $website = null,
        public ?array $openingHours = null,
        public ?array $openingHoursPeriods = null,
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
            'opening_hours_periods_json' => $this->openingHoursPeriods,
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
            'opening_hours_periods' => $this->openingHoursPeriods,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
            'images' => $this->images,
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
            // Normalized, not trusted: `fromArray()` rehydrates a CACHED payload,
            // so it can be handed a value written by an older, laxer path. Strict,
            // like the geocoder that fills the cache — see {@see OpeningHours}.
            openingHours: OpeningHours::fromProvider($data['opening_hours'] ?? null),
            // Already normalized on the way in, but re-normalized here for the same
            // reason: a cached payload may predate this field, or predate a rule.
            openingHoursPeriods: OpeningSchedule::salvage($data['opening_hours_periods'] ?? null),
            rating: isset($data['rating']) ? (float) $data['rating'] : null,
            ratingCount: isset($data['rating_count']) ? (int) $data['rating_count'] : null,
            images: is_array($data['images'] ?? null) ? $data['images'] : [],
        );
    }
}
