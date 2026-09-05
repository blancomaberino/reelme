<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ResolvesRequestInstant;
use App\Models\Place;
use App\Models\User;
use App\Models\UserPlaceTag;
use App\Services\Places\PlaceAggregations;
use App\Support\CachedReviews;
use App\Support\OpeningHours;
use App\Support\RequestLocale;
use App\Support\WeeklyHours;
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
    use ResolvesRequestInstant;

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
            'opening_hours' => $this->openingHoursForResource($request),
            // The computed status, or null when it is not knowable (T-155). The
            // structured periods and the timezone behind it are deliberately NOT
            // served: shipping a second, parseable copy of the week is how the
            // client came to invent its own reading last time (T-128). One
            // implementation decides open/closed — this one — and the client
            // renders its answer.
            'open_state' => $this->resource->openState(self::instant($request)),
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
            'google_reviews' => $this->googleReviewsForResource(),
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
     * The stored hours normalized to the contract shape (T-128): a flat list of
     * strings, or null. `salvage()`, not `fromProvider()` — see
     * {@see OpeningHours} for the strict-vs-lenient rule.
     *
     * Same read-boundary argument as the two normalizers below, different
     * structure: theirs stay private and local because this resource is their
     * only reader, while hours have four writers, so the decision lives in a
     * shared leaf they can all reach without this resource depending on any of
     * them.
     *
     * Validation on the way in is not enough on its own: `SuggestPlaceEditRequest`
     * accepted a bare `array` until T-128, and rows can reach the column by other
     * routes entirely (a console command, an import, an admin edit). Served raw,
     * an associative value lands as a JSON object, the client's `string[]` is a
     * lie, and `summarizeHours` degrades to an empty list — the hours row silently
     * disappears again with no error anywhere.
     *
     * @return list<string>|null
     */
    private function openingHoursForResource(Request $request): ?array
    {
        // Generated in the READER's language when the place has structured
        // periods (T-168), falling back to the source's verbatim prose when it
        // does not. Not a translation of that prose — see {@see WeeklyHours}.
        //
        // A HUMAN-LOCKED value wins outright, and this branch is the whole
        // reason the lock means anything here. Nothing curated can write
        // `opening_hours_periods_json` — Filament edits the LINES, the
        // suggest-an-edit request allows only the lines, and enrichment is the
        // sole writer of periods. So without this, a curator correcting "closes
        // 22:00, not 23:00" would save, lock the column, and watch the screen go
        // on showing the generated line from the stale periods — a correction
        // that is invisible and, because the lock then stops enrichment
        // refreshing anything, permanent. The lock says a person owns this
        // field; generating over it would say otherwise.
        if ($this->isFieldLocked('opening_hours_json')) {
            return OpeningHours::salvage($this->opening_hours_json);
        }

        return WeeklyHours::lines($this->opening_hours_periods_json, RequestLocale::resolve($request))
            ?? OpeningHours::salvage($this->opening_hours_json);
    }

    /**
     * The stored Google review snippets normalized to the contract shape
     * (T-128): EXACTLY the six keys `place.json` pins, one row per array entry.
     *
     * Same read-boundary argument as {@see galleryForResource()} below, and the
     * same private/local shape, because this resource is the only reader of
     * `google_reviews_json` that has to make the decision. The schema's items
     * block is `additionalProperties: false` with all six keys `required`, and
     * the contract test only ever sees rows the CURRENT
     * `GooglePlacesGeocoder::reviews()` writes — so a row from an earlier
     * version of that writer, or one hand-edited in Filament/tinker, would serve
     * a payload violating the contract with nothing to catch it. Missing keys
     * become null (the schema allows null on every one); unknown keys are
     * dropped; a non-array entry is skipped entirely.
     *
     * AND the count is capped here, not only in the writer. `place.json` says
     * `maxItems: 5` ({@see CachedReviews}); `GooglePlacesGeocoder::reviews()`
     * slices on the write — but that is the CURRENT writer, which is precisely
     * the assumption the paragraph above exists to distrust. A six-row legacy
     * column would serve six and break the contract on a live response, so the
     * cap belongs at the boundary that has to keep the promise. Extra rows are
     * dropped, not refused: the row already exists, and five real reviews beat
     * a 500.
     *
     * @return list<array{author: ?string, rating: float|int|null, text: ?string, relative_time: ?string, time: ?int, profile_photo_url: ?string}>
     */
    private function googleReviewsForResource(): array
    {
        $out = [];
        /** @var array<int, mixed> $rows — a legacy/hand-edited row may hold non-array items */
        // Sliced on the way IN, not on the way out: a corrupted import with
        // hundreds of rows would otherwise pay full normalization on every
        // detail request to keep five. The loop preserves order, so the first
        // five of the input are the first five of the output.
        $rows = array_slice((array) $this->google_reviews_json, 0, CachedReviews::MAX);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rating = $row['rating'] ?? null;
            $time = $row['time'] ?? null;
            $photo = $row['profile_photo_url'] ?? null;
            $out[] = [
                'author' => is_string($row['author'] ?? null) ? $row['author'] : null,
                // Clamped and scheme-checked to match {@see ReviewSnippet::fromArray()},
                // which decodes THIS SAME COLUMN for `review_sources[].snippets`.
                // The two readers had drifted apart: a legacy row with a rating of
                // 9 or a `javascript:` photo URL was rejected by one and served
                // by the other. Whichever guard is right, it cannot be right in
                // only one of two readers of one column.
                'rating' => is_int($rating) || is_float($rating) ? max(0.0, min(5.0, (float) $rating)) : null,
                'text' => is_string($row['text'] ?? null) ? $row['text'] : null,
                'relative_time' => is_string($row['relative_time'] ?? null) ? $row['relative_time'] : null,
                'time' => is_int($time) ? $time : null,
                'profile_photo_url' => is_string($photo) && preg_match('#^https?://#i', $photo) === 1 ? $photo : null,
            ];
        }

        return $out;
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
