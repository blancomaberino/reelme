<?php

namespace App\Http\Resources;

use App\Models\Place;
use App\Models\UserPlaceTag;
use App\Services\Places\PlaceAggregations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * GET /places/{id} shape (03-api-design §3.3) — the public place detail. IDs
 * serialize as strings (§1). Aggregated discovery tags/dishes come from every
 * contributing place_source; `rating.google` mirrors Google Places while
 * `rating.app` is the native review average. `review_sources[]` is the pluggable
 * multi-source aggregate (T-082): one normalized row per resolving provider.
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
     * The caller's own private tags (T-064), or null for a guest. Null keeps the
     * `my_tags` key off the payload entirely — it is NEVER present for anyone but
     * the owning viewer, and never carries another user's tags.
     *
     * @var Collection<int, UserPlaceTag>|null
     */
    private ?Collection $myTags = null;

    /**
     * @param  list<string>  $includes
     */
    public function withIncludes(array $includes): static
    {
        $this->includes = $includes;

        return $this;
    }

    /**
     * Attach the authed caller's private tags for this place. Pass null (the
     * default) for guests so `my_tags` is omitted rather than exposed empty.
     *
     * @param  Collection<int, UserPlaceTag>|null  $tags
     */
    public function withMyTags(?Collection $tags): static
    {
        $this->myTags = $tags;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $coords = $this->coordinates();
        $tags = PlaceAggregations::tags($this->resource);

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
            // Curated business picture (T-084): the main image drives the detail
            // hero (else the client falls back to the reel poster); the thumbnail
            // is what the map marker prefers.
            'image_url' => $this->image_url,
            'thumbnail_url' => $this->thumbnail_url,
            'cuisines' => $tags['cuisines'],
            'vibe_tags' => $tags['vibe_tags'],
            'dietary_tags' => $tags['dietary_tags'],
            'dishes' => $tags['dishes'],
            'dishes_updated_at' => $this->dishesUpdatedAt(),
            'dishes_language' => $this->dishesLanguage(),
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
            // Pluggable multi-source aggregate (T-082): per-source rating rows
            // (Google, native, Trustpilot, …), each with a deep link + snippets.
            // The `rating.google` / `rating.app` / `google_reviews` above stay for
            // back-compat. Providers that don't resolve are simply absent.
            'review_sources' => array_map(
                fn ($summary) => $summary->toArray(),
                $this->reviewSummaries(),
            ),
            'discounts' => PlaceAggregations::discounts($this->resource),
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
            'reviews' => $this->when(
                in_array('reviews', $this->includes, true),
                fn () => ReviewResource::collection($this->reviews),
            ),
            // Private per-user tags (T-064): present only for the authed owner;
            // absent for guests, never populated with another user's labels.
            'my_tags' => $this->when(
                $this->myTags !== null,
                fn () => UserPlaceTagResource::collection($this->myTags),
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
