<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceIndexRequest;
use App\Http\Requests\PlaceShowRequest;
use App\Http\Requests\PlaceSourcesRequest;
use App\Http\Resources\PlaceResource;
use App\Http\Resources\PlaceSourceResource;
use App\Http\Resources\PlaceSummaryResource;
use App\Models\Place;
use App\Support\KeysetCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

    public function index(PlaceIndexRequest $request): JsonResponse
    {
        $sort = $request->sort();
        $limit = $request->limit();
        $near = $request->nearPoint();

        $query = $this->visible()
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng');

        if ($near !== null) {
            $query->selectRaw(
                'ST_Distance(location, ST_MakePoint(?, ?)::geography) AS distance',
                [$near['lng'], $near['lat']],
            )->whereRaw(
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
            $query->anyTagSlug($tags);
        }

        if (($influencerId = $request->validated('influencer_id')) !== null) {
            $query->whereExists(fn ($sub) => $sub->from('place_sources')
                ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
                ->whereColumn('place_sources.place_id', 'places.id')
                ->where('source_posts.influencer_id', (int) $influencerId));
        }

        $cursor = KeysetCursor::decode($request->validated('cursor'), $sort, 2);
        $this->applySort($query, $sort, $cursor, $near);

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();

        $nextCursor = null;
        if ($hasMore && ($last = $page->last()) !== null) {
            $nextCursor = KeysetCursor::encode($sort, $this->cursorKeys($last, $sort));
        }

        return response()->json([
            'data' => PlaceSummaryResource::collection($page),
            'meta' => [
                'pagination' => [
                    'next_cursor' => $nextCursor,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
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
        $place->load([
            'sources' => fn ($q) => $q
                ->when($withSources, fn ($qq) => $qq->with(['sourcePost.influencer', 'sourcePost.mediaAssets', 'share.user']))
                ->orderByDesc('is_primary')->orderBy('id')->limit(self::SOURCE_CAP),
        ]);
        // Hidden (moderated) reviews never count toward the public aggregate.
        $place->loadCount(['reviews' => fn ($q) => $q->visible()])
            ->loadAvg(['reviews' => fn ($q) => $q->visible()], 'rating');

        if (in_array('reviews', $includes, true)) {
            $place->load([
                'reviews' => fn ($q) => $q->visible()->with('user')
                    ->orderByDesc('id')->limit(self::REVIEW_CAP),
            ]);
        }

        return response()->json([
            'data' => (new PlaceResource($place))->withIncludes($includes),
            'meta' => (object) $meta,
        ]);
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
            ->with(['sourcePost.influencer', 'sourcePost.mediaAssets', 'share.user'])
            ->orderBy('id');

        if ($cursor !== null) {
            $query->where('id', '>', (int) $cursor[0]);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'data' => PlaceSourceResource::collection($page),
            'meta' => [
                'pagination' => [
                    'next_cursor' => ($hasMore && $last !== null) ? KeysetCursor::encode('sources', [$last->id]) : null,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /**
     * @return Builder<Place>
     */
    private function visible(): Builder
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
                    $query->whereRaw('(shares_count, id) < (?, ?)', [(int) $cursor[0], (int) $cursor[1]]);
                }
                break;

            case 'distance':
                // Guaranteed by validation: distance requires near.
                assert($near !== null);
                $dist = 'ST_Distance(location, ST_MakePoint(?, ?)::geography)';
                $point = [$near['lng'], $near['lat']];
                $query->orderByRaw("{$dist} ASC, id ASC", $point);
                if ($cursor !== null) {
                    $query->whereRaw("({$dist}, id) > (?, ?)", [...$point, (float) $cursor[0], (int) $cursor[1]]);
                }
                break;

            default: // recent
                $query->orderByDesc('created_at')->orderByDesc('id');
                if ($cursor !== null) {
                    // The key binds into a ?::timestamp cast — anything Postgres
                    // can't parse would be a 500, so require a strict round-trip
                    // (rejects shape-valid-but-out-of-range values like month 13,
                    // which PHP would silently normalize) and PG's no-year-zero.
                    $ts = (string) $cursor[0];
                    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $ts);
                    if ($dt === false || $dt->format('Y-m-d H:i:s.u') !== $ts || str_starts_with($ts, '0000-')) {
                        throw ValidationException::withMessages(['cursor' => ['The cursor is malformed.']]);
                    }
                    $query->whereRaw('(created_at, id) < (?::timestamp, ?)', [$ts, (int) $cursor[1]]);
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
