<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceIndexRequest;
use App\Http\Requests\PlaceShowRequest;
use App\Http\Requests\PlaceSourcesRequest;
use App\Http\Resources\PlaceResource;
use App\Http\Resources\PlaceSourceResource;
use App\Http\Resources\PlaceSummaryResource;
use App\Models\Builders\PlaceQueryBuilder;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Moderation\BlockUsers;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public places surface (T-030, 03 §2.6): browse index with filters, place
 * detail (`?include=sources,offers`), and the attribution sources list.
 *
 * Visibility matches the map (T-029's documented deviation from "active
 * only"): `pending` places are on the map from their first auto-publish, so
 * they are browsable here too — `status` is exposed for client styling.
 * Merged places redirect (single hop) to their survivor on show and never
 * appear in the index.
 */
class PlaceController extends Controller
{
    /** Cap the embedded/aggregated sources so a very popular place stays bounded. */
    private const SOURCE_CAP = 24;

    /** Embedded native reviews on ?include=reviews; page the rest via /reviews. */
    private const REVIEW_CAP = 10;

    /** Embedded live offers on ?include=offers; page the rest via /offers (T-042). */
    private const OFFER_CAP = 10;

    public function index(PlaceIndexRequest $request): JsonResponse
    {
        $sort = $request->sort();
        $limit = $request->limit();
        $near = $request->nearPoint();

        $query = $this->visible()
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng');

        if ($near !== null) {
            $query->withDistanceFrom($near)->whereRaw(
                'ST_DWithin(location, ST_MakePoint(?, ?)::geography, ?)',
                [$near['lng'], $near['lat'], $request->radiusM()],
            );
        }

        if (($q = (string) ($request->validated('q') ?? '')) !== '') {
            $normalized = Place::normalizeName($q);
            if ($normalized !== '') {
                // Prefix match rides the trigram GIN via LIKE; `%` (pg_trgm
                // similarity) catches near-misses. Full-text search is T-031.
                $query->where(fn (Builder $w) => $w
                    ->where('normalized_name', 'like', $normalized.'%')
                    ->orWhereRaw('normalized_name % ?', [$normalized]));
            }
        }

        // tags[] pivot lands in T-031 — accepted now, no-op until it exists.
        $tags = $request->validated('tags');
        if (is_array($tags)) {
            $query->allTagSlugs($tags);
        }

        // Filter to places offering a discount for a given card/bank/wallet (T-079).
        if (($card = (string) ($request->validated('card') ?? '')) !== '') {
            $query->withPaymentCard($card);
        }

        // Filter to places serving a matching dish (T-157).
        if (($dish = (string) ($request->validated('dish') ?? '')) !== '') {
            $query->servingDish($dish);
        }

        if (($influencerId = $request->validated('influencer_id')) !== null) {
            $query->whereExists(fn ($sub) => $sub->from('place_sources')
                ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
                ->whereColumn('place_sources.place_id', 'places.id')
                ->where('source_posts.influencer_id', (int) $influencerId));
        }

        $cursor = KeysetCursor::decode($request->validated('cursor'), $sort, 2);
        $this->applySort($query, $sort, $cursor, $near);

        $page = KeysetPage::query($query, $limit, $sort, fn (Place $last) => $this->cursorKeys($last, $sort));

        return ApiResponse::page(PlaceSummaryResource::collection($page->items), $page);
    }

    /**
     * Distinct card/bank/wallet discount labels across visible places (T-079) —
     * the facet source for the map's "filter by card" chips, ordered by how many
     * places offer each. `card` is the same display label {@see Place::discountCard()}
     * computes (resolved issuer → scheme → @handle), so a returned value feeds
     * straight back into `?card=`.
     */
    public function paymentCards(): JsonResponse
    {
        // The facet unnests every visible place's discount snapshots (no index on
        // the jsonb) — cache it: the set only shifts as new extractions publish,
        // and clients poll it on a 10-min staleTime. Short TTL keeps it fresh
        // enough while collapsing the repeated full scan.
        $data = Cache::remember('places:payment-cards', now()->addMinutes(5), function () {
            // Same array-guarded jsonb + label expression the card filter uses, so
            // the facet lists exactly the labels `?card=` matches (Place::discountCard()).
            $inner = Place::query()
                ->publiclyVisible()
                ->join('place_sources', 'place_sources.place_id', '=', 'places.id')
                ->crossJoin(DB::raw('LATERAL jsonb_array_elements('.Place::DISCOUNTS_JSONB.') AS d'))
                ->whereRaw(Place::DISCOUNT_HAS_TERMS)
                ->selectRaw(Place::DISCOUNT_CARD_SQL.' AS card, places.id AS place_id');

            return DB::query()
                ->fromSub($inner, 't')
                ->whereRaw("card IS NOT NULL AND card <> ''")
                ->groupBy('card')
                ->selectRaw('card, COUNT(DISTINCT place_id) AS uses')
                ->orderByDesc('uses')
                ->orderBy('card')
                ->limit(40)
                ->get()
                ->map(fn ($r) => ['card' => (string) $r->card, 'count' => (int) $r->uses])
                ->all();
        });

        return ApiResponse::collection($data);
    }

    public function show(PlaceShowRequest $request, Place $place): JsonResponse
    {
        $meta = [];

        // A merged place is a tombstone: follow the (single-hop, per 02 §3.8)
        // pointer and answer with the survivor, flagged so clients can update
        // their canonical reference.
        if ($place->merged_into_place_id !== null || $place->status === PlaceStatus::Merged) {
            $terminal = Place::query()->find($place->merged_into_place_id);
            if ($terminal === null || $terminal->merged_into_place_id !== null || $terminal->status === PlaceStatus::Merged) {
                Log::warning('places.merged_chain_not_single_hop', ['place_id' => $place->id]);
                abort(404);
            }
            $meta['redirected_from'] = $place->slug;
            $place = $terminal;
        }

        abort_unless(in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true), 404);

        $includes = $request->includes();
        $withSources = in_array('sources', $includes, true);

        // Sources are always loaded for tag aggregation; their relations only
        // matter to the ?include=sources embed. Reviews reduce to aggregates —
        // never load the rows (unbounded as T-059 reviews accumulate).
        // Blocked accounts drop out of the EMBED too, not just the paginated
        // /sources list (T-054). They are the same attribution rendered on the
        // same screen; filtering one and not the other means the name reappears
        // the moment the client asks for `?include=sources`.
        $invisible = app(BlockUsers::class)->invisibleTo($request->user('sanctum')?->id);

        $place->load([
            // `dishes` is always loaded, not only for the embed: the tag
            // aggregation reads it for every request (T-157).
            'sources' => fn ($q) => $q
                ->with('dishes')
                ->when($withSources, fn ($qq) => $qq->with(['sourcePost.influencer', 'sourcePost.mediaAssets', 'share.user']))
                ->when($invisible !== [], fn ($qq) => $qq
                    ->whereHas('share', fn ($sq) => $sq->whereNotIn('shares.user_id', $invisible)))
                ->orderByDesc('is_primary')->orderBy('id')->limit(self::SOURCE_CAP),
            // Cached external review summaries (T-082) — read by the Trustpilot
            // ReviewSource driver; eager-loaded so `review_sources[]` costs no N+1.
            'externalReviews',
        ]);
        // Hidden (moderated) reviews never count toward the public aggregate.
        $place->loadCount(['reviews' => fn ($q) => $q->visible()])
            ->loadAvg(['reviews' => fn ($q) => $q->visible()], 'rating');

        // Live offers only (T-042): `active()` evaluates the validity window, so
        // an offer whose `ends_at` passed overnight drops out even though its
        // status column still says `active`.
        if (in_array('offers', $includes, true)) {
            $place->load([
                'offers' => fn ($q) => $q->active()->orderByDesc('id')->limit(self::OFFER_CAP),
            ]);
        }

        if (in_array('reviews', $includes, true)) {
            $place->load([
                'reviews' => fn ($q) => $q->visible()->with('user')
                    ->orderByDesc('id')->limit(self::REVIEW_CAP),
            ]);
        }

        // Private per-user tags (T-064): attach the caller's own labels for the
        // owner-only `my_tags` field. Guests get null → the key is omitted, so
        // one user's annotations can never leak to another. Optional auth is
        // resolved via the sanctum guard (the route itself is public).
        $viewer = $request->user('sanctum');
        $myTags = $viewer !== null
            ? $place->userPlaceTags()->where('user_id', $viewer->id)->orderByDesc('id')->get()
            : null;

        return ApiResponse::item((new PlaceResource($place))->withIncludes($includes)->withMyTags($myTags), $meta);
    }

    public function sources(PlaceSourcesRequest $request, Place $place): JsonResponse
    {
        // Unlike show(), a merged tombstone 404s here rather than redirecting:
        // clients must refresh the canonical place from show() (which carries
        // meta.redirected_from) before paging its sub-resources.
        abort_unless(
            $place->merged_into_place_id === null
            && in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true),
            404,
        );

        $limit = $request->limit();
        $cursor = KeysetCursor::decode($request->validated('cursor'), 'sources', 1);

        $query = $place->sources()
            ->with(['sourcePost.influencer', 'sourcePost.mediaAssets', 'share.user', 'dishes'])
            ->orderBy('id');

        // A blocked account's contribution drops out of the attribution list
        // (T-054). The PLACE stays — it is community data with many sources,
        // and removing a restaurant from the map because one blocked account
        // also shared it would punish the blocker. But their name appearing
        // under it is exactly what blocking is supposed to stop. Mirrored in
        // show()'s embed above, which renders the same attribution.
        $invisible = app(BlockUsers::class)->invisibleTo($request->user('sanctum')?->id);
        if ($invisible !== []) {
            $query->whereHas('share', fn ($q) => $q->whereNotIn('shares.user_id', $invisible));
        }

        if ($cursor !== null) {
            $query->where('id', '>', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, 'sources', fn (PlaceSource $last) => [$last->id]);

        return ApiResponse::page(PlaceSourceResource::collection($page->items), $page);
    }

    private function visible(): PlaceQueryBuilder
    {
        return Place::query()->publiclyVisible();
    }

    /**
     * Apply ORDER BY + the keyset WHERE for the requested sort. Row-value
     * comparisons keep pagination gap- and duplicate-free under concurrent
     * inserts; `id` is always the tiebreaker.
     *
     * @param  Builder<Place>  $query
     * @param  list<int|float|string>|null  $cursor
     * @param  array{lat: float, lng: float}|null  $near
     */
    private function applySort(Builder $query, string $sort, ?array $cursor, ?array $near): void
    {
        switch ($sort) {
            case 'popular':
                $query->orderByDesc('shares_count')->orderByDesc('id');
                if ($cursor !== null) {
                    $query->whereRaw('(shares_count, id) < (?, ?)', [KeysetCursor::intKey($cursor[0]), KeysetCursor::intKey($cursor[1])]);
                }
                break;

            case 'distance':
                // Guaranteed by validation: distance requires near.
                assert($near !== null);
                // SQL and bindings together, from the one place that knows the
                // `[lng, lat]` order — retyping the pair here is how a mirrored
                // point gets measured with no error and no red test.
                [$dist, $point] = PlaceQueryBuilder::distanceFrom($near);
                $query->orderByRaw("{$dist} ASC, id ASC", $point);
                if ($cursor !== null) {
                    $query->whereRaw("({$dist}, id) > (?, ?)", [...$point, (float) $cursor[0], KeysetCursor::intKey($cursor[1])]);
                }
                break;

            default: // recent
                $query->orderByDesc('created_at')->orderByDesc('id');
                if ($cursor !== null) {
                    // The key binds into a ?::timestamp cast; timestampKey() does
                    // the strict round-trip (rejecting month-13 / year-0) so an
                    // unparseable value 422s instead of 500-ing.
                    $ts = KeysetCursor::timestampKey($cursor[0]);
                    $query->whereRaw('(created_at, id) < (?::timestamp, ?)', [$ts, KeysetCursor::intKey($cursor[1])]);
                }
        }
    }

    /**
     * The keyset values for the last row of a page, in sort order.
     *
     * @return list<int|float|string>
     */
    private function cursorKeys(Place $place, string $sort): array
    {
        return match ($sort) {
            'popular' => [(int) $place->shares_count, $place->id],
            'distance' => [(float) $place->getAttribute('distance'), $place->id],
            default => [$place->created_at->format('Y-m-d H:i:s.u'), $place->id],
        };
    }
}
