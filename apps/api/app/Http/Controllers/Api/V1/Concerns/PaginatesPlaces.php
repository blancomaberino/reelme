<?php

namespace App\Http\Controllers\Api\V1\Concerns;

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
     */
    protected function placeListResponse(Builder $base, PlaceListingRequest $request): JsonResponse
    {
        $sort = $request->sort();
        $limit = $request->limit();

        $query = $base
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            // Poster for the list card (T-070 thumbnail); mediaAssets feed the
            // ResolvesThumbnail fallback chain.
            ->with(['primarySource.sourcePost.mediaAssets']);

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

        $cursor = KeysetCursor::decode($request->validated('cursor'), 'my-places', 2);
        $this->applyPlaceSort($query, $sort, $cursor);

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();

        $nextCursor = null;
        if ($hasMore && ($last = $page->last()) !== null) {
            $keys = $sort === 'popular'
                ? [(int) $last->shares_count, $last->id]
                : [$last->created_at->format('Y-m-d H:i:s.u'), $last->id];
            $nextCursor = KeysetCursor::encode('my-places', $keys);
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
            $ts = (string) $cursor[0];
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $ts);
            if ($dt === false || $dt->format('Y-m-d H:i:s.u') !== $ts || str_starts_with($ts, '0000-')) {
                abort(422, 'The cursor is malformed.');
            }
            $query->whereRaw('(created_at, id) < (?::timestamp, ?)', [$ts, KeysetCursor::intKey($cursor[1])]);
        }
    }
}
