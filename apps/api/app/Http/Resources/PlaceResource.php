<?php

namespace App\Http\Resources;

use App\Models\Place;
use App\Models\User;
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
 * `?include=offers` embeds the venue's live offers (T-042, OfferResource shape).
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
            // Whether the venue has a verified operator (T-041). A boolean, not
            // the claim or the owner: who runs a restaurant is not public, but
            // "the people who run this place are here" is the signal a diner
            // acts on — and it is what gates the offer surfaces in M4.
            'claimed' => $this->verifiedClaim()->exists(),
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'category' => $this->cuisine_primary,
            'price_range' => $this->price_range,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'address' => $this->formattedAddress(),
            // The street line on its own, beside the display string built from
            // it. The suggest-an-edit form (T-083) has to round-trip what it is
            // correcting, and it cannot parse a street back out of
            // "Calle X, Montevideo, UY" — every other suggestable field was
            // already readable under its own name.
            'address_line1' => $this->address_line1,
            // Whether THIS viewer may edit the place directly — a verified
            // operator (T-041). Drives the client's choice between "suggest a
            // change" and "edit", and is re-derived from the claim on every
            // request, so a revoked claim revokes the direct edit with it.
            // Guests get false rather than a missing key: an absent boolean
            // reads as "unknown" at the call site and is one `??` from being
            // treated as true.
            'can_edit' => $this->viewerOwnsPlace($request),
            'google_place_id' => $this->google_place_id,
            'opening_hours' => $this->opening_hours_json,
            'phone' => $this->phone,
            'website' => $this->website,
            // Curated business picture (T-084): the main image drives the detail
            // hero (else the client falls back to the reel poster); the thumbnail
            // is what the map marker prefers.
            'image_url' => $this->image_url,
            'thumbnail_url' => $this->thumbnail_url,
            // Ordered business gallery (T-099): owned website images first, then
            // business-attributed Google photos, then fill. `image_url` is
            // gallery[0]; the client shows a carousel only when length > 1.
            'gallery' => $this->galleryForResource(),
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
            // Only offers that are LIVE — the controller loads them through
            // Offer::active(), so a draft, a paused promo, or one whose window
            // lapsed overnight never reaches a diner from here (T-042).
            'offers' => $this->when(
                in_array('offers', $this->includes, true),
                fn () => OfferResource::collection($this->offers),
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

    /**
     * Does the authenticated viewer operate this place (T-041)?
     *
     * Resolved through the `sanctum` guard exactly as `my_tags` is, because this
     * resource is served from a PUBLIC route: `$request->user()` with no guard
     * named consults the session/web guard and answers null for every token
     * request, which would silently tell every operator they may not edit.
     */
    private function viewerOwnsPlace(Request $request): bool
    {
        $viewer = $request->user('sanctum');

        return $viewer instanceof User && $viewer->ownsPlace($this->resource);
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

    /**
     * The stored gallery normalized to the contract shape (T-099): a list of
     * `{ url, source, attribution }`. Defends against a legacy/malformed
     * `gallery_json` (non-list, missing keys) so the contract stays valid.
     *
     * @return list<array{url: string, source: string, attribution: ?string}>
     */
    private function galleryForResource(): array
    {
        $out = [];
        /** @var array<int, mixed> $entries — a legacy/malformed row may hold non-array items */
        $entries = (array) $this->gallery_json;
        foreach ($entries as $entry) {
            // Keep only contract-valid rows: an http(s) url and an enum source
            // (a hand-edited/legacy value could otherwise fail schema validation).
            if (! is_array($entry) || ! is_string($entry['url'] ?? null) || preg_match('#^https?://#i', $entry['url']) !== 1) {
                continue;
            }
            $source = is_string($entry['source'] ?? null) && in_array($entry['source'], ['website', 'google', 'reel'], true)
                ? $entry['source']
                : 'website';
            $out[] = [
                'url' => $entry['url'],
                'source' => $source,
                'attribution' => is_string($entry['attribution'] ?? null) && $entry['attribution'] !== '' ? $entry['attribution'] : null,
            ];
        }

        return $out;
    }
}
