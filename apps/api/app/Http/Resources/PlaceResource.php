<?php

namespace App\Http\Resources;

use App\Models\Place;
use App\Models\PlaceSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /places/{id} shape (03-api-design §3.3) — the public place detail. IDs
 * serialize as strings (§1). Aggregated discovery tags/dishes come from every
 * contributing place_source; `rating.google` mirrors Google Places while
 * `rating.app` is the native review average.
 *
 * @mixin Place
 */
class PlaceResource extends JsonResource
{
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
                    'value' => $this->appRatingValue(),
                    'count' => $this->reviews->count(),
                ],
            ],
            'google_reviews' => $this->google_reviews_json ?? [],
            'sources' => $this->sourcePayload(),
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

    /** Native review average (rounded to 1dp), or null when there are none. */
    private function appRatingValue(): ?float
    {
        if ($this->reviews->isEmpty()) {
            return null;
        }

        return round((float) $this->reviews->avg('rating'), 1);
    }

    /**
     * Contributing sources, primary first then by id — one entry per place_source
     * with its reel URL and crediting influencer.
     *
     * @return list<array<string, mixed>>
     */
    private function sourcePayload(): array
    {
        return $this->sources
            ->sortBy([['is_primary', 'desc'], ['id', 'asc']])
            ->map(function (PlaceSource $source): array {
                $post = $source->sourcePost;
                $influencer = $post?->influencer;

                return [
                    'reel_url' => $post?->url,
                    'platform' => $post?->platform->value,
                    'account' => $influencer?->handle,
                    'account_name' => $influencer?->display_name,
                    'confidence' => null,
                    'shared_at' => $post?->posted_at?->toIso8601ZuluString(),
                ];
            })
            ->values()
            ->all();
    }
}
