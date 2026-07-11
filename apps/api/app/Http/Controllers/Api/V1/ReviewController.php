<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceSourcesRequest;
use App\Http\Requests\ReviewUpsertRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Place;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Support\KeysetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Native reviews (T-059, 03 §2.6): public list per place, authenticated
 * one-review-per-user write/delete, and a report endpoint feeding the
 * Filament moderation queue. `rating.app` on the place detail aggregates the
 * same table at read time, so it is consistent under concurrent writes by
 * construction — there is no counter cache to drift.
 */
class ReviewController extends Controller
{
    public function index(PlaceSourcesRequest $request, Place $place): JsonResponse
    {
        $this->assertVisible($place);

        $limit = $request->limit();
        $cursor = KeysetCursor::decode($request->validated('cursor'), 'reviews', 1);

        $query = $place->reviews()->visible()->with('user')->orderByDesc('id');
        if ($cursor !== null) {
            $query->where('id', '<', (int) $cursor[0]);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'data' => ReviewResource::collection($page),
            'meta' => [
                'pagination' => [
                    'next_cursor' => ($hasMore && $last !== null) ? KeysetCursor::encode('reviews', [$last->id]) : null,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /** POST — create; 409 when the caller already reviewed this place. */
    public function store(ReviewUpsertRequest $request, Place $place): JsonResponse
    {
        $this->assertVisible($place);
        $userId = (int) $request->user()->id;

        if ($place->reviews()->where('user_id', $userId)->exists()) {
            abort(409, 'You have already reviewed this place — use PUT to update it.');
        }

        $review = $this->write($place, $userId, $request);

        return $this->reviewResponse($request, $place, $review, 201);
    }

    /** PUT — idempotent upsert of the caller's single review. */
    public function upsert(ReviewUpsertRequest $request, Place $place): JsonResponse
    {
        $this->assertVisible($place);

        $review = $this->write($place, (int) $request->user()->id, $request);

        return $this->reviewResponse($request, $place, $review, 200);
    }

    public function destroy(Request $request, Place $place): JsonResponse
    {
        $this->assertVisible($place);

        $deleted = $place->reviews()
            ->where('user_id', $request->user()->id)
            ->delete();

        abort_if($deleted === 0, 404, 'You have no review for this place.');

        return response()->json(['data' => null, 'meta' => $this->ratingMeta($place)]);
    }

    /** Report someone's review — once per user, idempotent on repeat. */
    public function report(Request $request, Review $review): JsonResponse
    {
        abort_if($review->is_hidden, 404);

        $validated = $request->validate([
            'reason' => ['required', Rule::in(ReviewReport::REASONS)],
        ]);

        // Reporting your own review makes no sense — treat as a no-op success.
        if ($review->user_id !== $request->user()->id) {
            ReviewReport::query()->firstOrCreate(
                ['review_id' => $review->id, 'user_id' => $request->user()->id],
                ['reason' => $validated['reason']],
            );
        }

        return response()->json(['data' => ['reported' => true], 'meta' => (object) []]);
    }

    private function write(Place $place, int $userId, ReviewUpsertRequest $request): Review
    {
        return Review::query()->updateOrCreate(
            ['place_id' => $place->id, 'user_id' => $userId],
            [
                'rating' => (int) $request->validated('rating'),
                'body' => $request->validated('body'),
            ],
        );
    }

    private function reviewResponse(Request $request, Place $place, Review $review, int $status): JsonResponse
    {
        $review->setRelation('user', $request->user());

        return response()->json([
            'data' => new ReviewResource($review),
            'meta' => $this->ratingMeta($place),
        ], $status);
    }

    /**
     * Fresh aggregate after a write — computed from the table, never cached.
     *
     * @return array<string, mixed>
     */
    private function ratingMeta(Place $place): array
    {
        $agg = $place->reviews()->visible()
            ->toBase()
            ->selectRaw('count(*) AS c, avg(rating) AS a')
            ->first();

        $count = (int) ($agg->c ?? 0);

        return [
            'rating' => [
                'app' => [
                    'value' => $count > 0 ? round((float) $agg->a, 1) : null,
                    'count' => $count,
                ],
            ],
        ];
    }

    private function assertVisible(Place $place): void
    {
        abort_unless(
            $place->merged_into_place_id === null
            && in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true),
            404,
        );
    }
}
