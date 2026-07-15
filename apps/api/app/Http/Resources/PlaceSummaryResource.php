<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesThumbnail;
use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Index-row shape for `GET /places` (T-030, 03 §2.6) — the browse/list card,
 * shared by the personal "my places" and per-user places lists (T-071).
 * Expects `lat`/`lng` (and `distance` when a near-point was given) selected as
 * SQL aliases by the index query; it never issues per-row coordinate queries.
 * `thumbnail_url` is present only when the query eager-loaded `primarySource`
 * (the my-places / per-user lists do; the plain browse index does not).
 *
 * @mixin Place
 */
class PlaceSummaryResource extends JsonResource
{
    use ResolvesThumbnail;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'lat' => round((float) $this->getAttribute('lat'), 6),
            'lng' => round((float) $this->getAttribute('lng'), 6),
            'category' => $this->cuisine_primary,
            'price_range' => $this->price_range,
            'city' => $this->city,
            'country_code' => $this->country_code,
            // The primary reel poster (T-070/T-034), when the query loaded it.
            'thumbnail_url' => $this->whenLoaded(
                'primarySource',
                fn () => $this->resolveThumbnail($this->primarySource?->sourcePost),
            ),
            'source_count' => (int) $this->shares_count,
            'rating' => [
                'google' => [
                    'value' => $this->google_rating !== null ? (float) $this->google_rating : null,
                    'count' => (int) ($this->google_rating_count ?? 0),
                ],
            ],
            'distance_m' => $this->getAttribute('distance') !== null
                ? round((float) $this->getAttribute('distance'), 1)
                : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
