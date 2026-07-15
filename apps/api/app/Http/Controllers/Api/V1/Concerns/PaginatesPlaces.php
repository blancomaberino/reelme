<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\MediaKind;
use App\Http\Requests\PlaceListingRequest;
use App\Http\Resources\PlaceSummaryResource;
use App\Models\Place;
use App\Support\KeysetCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Shared list-view machinery for the personal + per-user place lists (T-071).
 * A caller supplies the base ownership scope (mine, or a user's published
 * shares) and this applies the common faceted filters (country, type, tags,
 * q), sort, and keyset pagination, then renders {@see PlaceSummaryResource}.
 * The two callers differ only in that base scope — everything else is one code
 * path so the "my map" and "my places" list stay two views of one dataset.
 */
trait PaginatesPlaces
{
    /**
     * @param  Builder<Place>  $base  the ownership-scoped, publicly-visible query
     * @param  list<array{0: string, 1: list<mixed>}>  $rawSelects  extra `[expr, bindings]`
     *                                                              columns a caller adds (e.g. /me/places' per-row `mine` provenance);
     *                                                              select bindings sort before the WHERE, so order is irrelevant
     */
    protected function placeListResponse(Builder $base, PlaceListingRequest $request, array $rawSelects = []): JsonResponse
    {
        $sort = $request->sort();
        $limit = $request->limit();

        $query = $base
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            // Poster for the list card (T-070 thumbnail). Only the thumbnail-kind
            // asset is read (ResolvesThumbnail falls back to the oembed column),
            // so constrain the load — a post can carry dozens of keyframe assets
            // (T-013 carousels) that would otherwise be hydrated for nothing.
            ->with(['primarySource.sourcePost.mediaAssets' => fn ($q) => $q->where('kind', MediaKind::Thumbnail)]);

        foreach ($rawSelects as [$expr, $bindings]) {
            $query->selectRaw($expr, $bindings);
        }

        if (($country = $request->validated('country')) !== null) {
            $query->where('places.country_code', $country);
        }

        if (($type = $request->validated('type')) !== null) {
            $query->where('places.cuisine_primary', $type);
        }

        $tags = $request->validated('tags');
        if (is_array($tags) && $tags !== []) {
            $query->anyTagSlug($tags);
        }

        if (($q = (string) ($request->validated('q') ?? '')) !== '') {
            $normalized = Place::normalizeName($q);
            if ($normalized !== '') {
                $query->where(fn (Builder $w) => $w
                    ->where('normalized_name', 'like', $normalized.'%')
                    ->orWhereRaw('normalized_name % ?', [$normalized]));
            }
        }

        // Namespace the cursor by sort so switching sort mid-pagination is a
        // clean 422 (KeysetCursor's mismatch guard), matching PlaceController.
        $namespace = 'my-places-'.$sort;
        $cursor = KeysetCursor::decode($request->validated('cursor'), $namespace, 2);
        $this->applyPlaceSort($query, $sort, $cursor);

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();

        $nextCursor = null;
        if ($hasMore && ($last = $page->last()) !== null) {
            $nextCursor = KeysetCursor::encode($namespace, $this->placeCursorKeys($last, $sort));
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

    /**
     * ORDER BY + keyset WHERE for the list. Row-value comparisons keep
     * pagination gap-free under concurrent inserts; `id` is the tiebreaker.
     *
     * @param  Builder<Place>  $query
     * @param  list<int|float|string>|null  $cursor
     */
    private function applyPlaceSort(Builder $query, string $sort, ?array $cursor): void
    {
        if ($sort === 'popular') {
            $query->orderByDesc('shares_count')->orderByDesc('id');
            if ($cursor !== null) {
                $query->whereRaw('(shares_count, id) < (?, ?)', [
                    KeysetCursor::intKey($cursor[0]),
                    KeysetCursor::intKey($cursor[1]),
                ]);
            }

            return;
        }

        // recent (default)
        $query->orderByDesc('created_at')->orderByDesc('id');
        if ($cursor !== null) {
            $ts = KeysetCursor::timestampKey($cursor[0]);
            $query->whereRaw('(created_at, id) < (?::timestamp, ?)', [$ts, KeysetCursor::intKey($cursor[1])]);
        }
    }

    /**
     * The keyset values for a page's last row, in sort order — the encode-side
     * companion to {@see applyPlaceSort()} so a sort's ORDER BY and its cursor
     * shape stay defined together.
     *
     * @return list<int|float|string>
     */
    private function placeCursorKeys(Place $last, string $sort): array
    {
        return $sort === 'popular'
            ? [(int) $last->shares_count, $last->id]
            : [$last->created_at->format('Y-m-d H:i:s.u'), $last->id];
    }
}
