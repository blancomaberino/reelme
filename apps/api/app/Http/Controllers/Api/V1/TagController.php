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
    /**
     * Correlated usage count — keyset-comparable (an alias would not be
     * addressable in WHERE). Counts publicly visible places only: merged
     * tombstones shed their pivots on merge, but hidden places keep theirs
     * (hide is reversible) and must not inflate popularity.
     */
    private const COUNT_EXPR = "(select count(*) from place_tag join places on places.id = place_tag.place_id where place_tag.tag_id = tags.id and places.status in ('pending', 'active') and places.merged_into_place_id is null)";

    public function index(TagIndexRequest $request): JsonResponse
    {
        $limit = $request->limit();
        $popular = $request->popular();
        $sort = $popular ? 'tags-popular' : 'tags-alpha';

        // Scoped the same way as COUNT_EXPR — the keyset WHERE and the ORDER BY
        // alias must agree or popular-cursor pages skip/repeat rows.
        $query = Tag::query()->withCount(['places' => fn ($q) => $q->publiclyVisible()]);

        if (($q = trim((string) ($request->validated('q') ?? ''))) !== '') {
            // Escape LIKE metacharacters — q is a literal prefix, not a pattern.
            $needle = addcslashes(mb_strtolower($q), '%_\\');
            $query->where(fn ($w) => $w
                ->whereRaw("slug like ? escape '\\'", [$needle.'%'])
                ->orWhereRaw("lower(name) like ? escape '\\'", [$needle.'%'])
                // Also prefix-match any localized label (ADR-084 #3), so a Spanish
                // query ("informal") finds the English-stored tag ("casual").
                ->orWhereRaw(
                    "exists (select 1 from jsonb_each_text(coalesce(name_i18n, '{}'::jsonb)) e where lower(e.value) like ? escape '\\')",
                    [$needle.'%'],
                ));
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
