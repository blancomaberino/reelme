<?php

namespace App\Http\Resources;

use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /places/{id} shape (03-api-design §3.3) — the public place detail. IDs
 * serialize as strings (§1). Aggregated discovery tags/dishes come from every
 * contributing place_source; `rating.google` mirrors Google Places while
 * `rating.app` is the native review average.
 *
 * `?include=sources` embeds the attribution list (PlaceSourceResource shape);
 * `?include=offers` is accepted-but-empty until M4 (T-030).
 *
 * @mixin Place
 */
class PlaceResource extends JsonResource
{
    /** @var list<string> */
    private array $includes = [];

    /**
     * @param  list<string>  $includes
     */
    public function withIncludes(array $includes): static
    {
        $this->includes = $includes;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $coords = $this->coordinates();
        $tags = $this->aggregatedTags();

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'category' => $this->cuisine_primary,
            'price_range' => $this->price_range,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'address' => $this->formattedAddress(),
            'google_place_id' => $this->google_place_id,
            'opening_hours' => $this->opening_hours_json,
            'phone' => $this->phone,
            'website' => $this->website,
            'cuisines' => $tags['cuisines'],
            'vibe_tags' => $tags['vibe_tags'],
            'dietary_tags' => $tags['dietary_tags'],
            'dishes' => $tags['dishes'],
            'source_count' => (int) $this->shares_count,
            'rating' => [
                'google' => [
                    'value' => $this->google_rating !== null ? (float) $this->google_rating : null,
                    'count' => (int) ($this->google_rating_count ?? 0),
                ],
                'app' => [
                    'value' => ((int) $this->reviews_count) > 0
                        ? round((float) $this->reviews_avg_rating, 1)
                        : null,
                    'count' => (int) $this->reviews_count,
                ],
            ],
            'google_reviews' => $this->google_reviews_json ?? [],
            'sources' => $this->when(
                in_array('sources', $this->includes, true),
                fn () => PlaceSourceResource::collection(
                    $this->sources->sortBy([['is_primary', 'desc'], ['id', 'asc']])->values()
                ),
            ),
            'offers' => $this->when(
                in_array('offers', $this->includes, true),
                [], // offers ship in M4 — the include is accepted-but-empty (03 §2.6)
            ),
        ];
    }

    /** Comma-join the non-null address parts (line1, city, region, country). */
    private function formattedAddress(): string
    {
        $parts = array_filter(
            [$this->address_line1, $this->city, $this->region, $this->country_code],
            fn ($p) => $p !== null && trim((string) $p) !== '',
        );

        return implode(', ', array_map(fn ($p) => trim((string) $p), $parts));
    }
}
