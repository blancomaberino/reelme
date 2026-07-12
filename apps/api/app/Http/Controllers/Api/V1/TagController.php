<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TagIndexRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Support\KeysetCursor;
use Illuminate\Http\JsonResponse;

/**
 * Public tags index (T-031, 03 §2.11): `?q=` prefix search on slug/name,
 * `?popular=1` orders by place-usage count. Keyset-cursor paginated like the
 * places index.
 */
class TagController extends Controller
{
    /** Correlated usage count — keyset-comparable (an alias would not be addressable in WHERE). */
    private const COUNT_EXPR = '(select count(*) from place_tag where place_tag.tag_id = tags.id)';

    public function index(TagIndexRequest $request): JsonResponse
    {
        $limit = $request->limit();
        $popular = $request->popular();
        $sort = $popular ? 'tags-popular' : 'tags-alpha';

        $query = Tag::query()->withCount('places');

        if (($q = trim((string) ($request->validated('q') ?? ''))) !== '') {
            // Escape LIKE metacharacters — q is a literal prefix, not a pattern.
            $needle = addcslashes(mb_strtolower($q), '%_\\');
            $query->where(fn ($w) => $w
                ->whereRaw("slug like ? escape '\\'", [$needle.'%'])
                ->orWhereRaw("lower(name) like ? escape '\\'", [$needle.'%']));
        }

        $cursor = KeysetCursor::decode($request->validated('cursor'), $sort, 2);

        if ($popular) {
            // ORDER BY the withCount alias (addressable in PG); only the keyset
            // WHERE needs the raw expression.
            $query->orderByDesc('places_count')->orderByDesc('id');
            if ($cursor !== null) {
                $query->whereRaw('('.self::COUNT_EXPR.', id) < (?, ?)', [KeysetCursor::intKey($cursor[0]), KeysetCursor::intKey($cursor[1])]);
            }
        } else {
            $query->orderBy('slug')->orderBy('id');
            if ($cursor !== null) {
                $query->whereRaw('(slug, id) > (?, ?)', [(string) $cursor[0], KeysetCursor::intKey($cursor[1])]);
            }
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        $nextCursor = null;
        if ($hasMore && $last !== null) {
            $nextCursor = KeysetCursor::encode($sort, $popular
                ? [(int) $last->places_count, $last->id]
                : [$last->slug, $last->id]);
        }

        return response()->json([
            'data' => TagResource::collection($page),
            'meta' => [
                'pagination' => [
                    'next_cursor' => $nextCursor,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }
}
