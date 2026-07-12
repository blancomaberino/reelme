<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedRequest;
use App\Http\Resources\FeedItemResource;
use App\Models\Place;
use App\Models\Share;
use App\Support\KeysetCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Discovery feed (T-034, 03 §2.8): reverse-chronological published shares.
 * M2 ships `scope=global` (public); `scope=following` is an auth-required
 * stub with a stable response shape until T-037 wires follows.
 *
 * Place visibility matches every other public surface (pending + active,
 * never merged) — the spec's "active only" has the same documented
 * deviation as the map (T-029): a first auto-publish stays pending.
 */
class FeedController extends Controller
{
    public function index(FeedRequest $request): JsonResponse
    {
        $limit = $request->limit();

        if ($request->scope() === 'following') {
            abort_unless($request->user('sanctum') !== null, 401);

            return response()->json([
                'data' => [],
                'meta' => [
                    'scope' => 'following', // populated by T-037
                    'pagination' => ['next_cursor' => null, 'prev_cursor' => null, 'limit' => $limit],
                ],
            ]);
        }

        $cursor = KeysetCursor::decode($request->validated('cursor'), 'feed', 2);

        $query = Share::query()
            ->where('status', ShareStatus::Published)
            ->whereNotNull('published_place_source_id')
            ->whereNotNull('published_at')
            ->whereHas('publishedPlaceSource.place', function ($q) {
                /** @var Builder<Place> $q */
                $q->publiclyVisible();
            })
            ->with([
                'user',
                'sourcePost.influencer',
                'sourcePost.mediaAssets',
                'publishedPlaceSource.place' => fn ($q) => $q
                    ->select('places.*')
                    ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng'),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($cursor !== null) {
            // Same strict shape check as the places recent cursor — the key
            // binds into a ?::timestamptz cast and must never 500.
            $ts = (string) $cursor[0];
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $ts);
            if ($dt === false || $dt->format('Y-m-d H:i:s.u') !== $ts || str_starts_with($ts, '0000-')) {
                throw ValidationException::withMessages(['cursor' => ['The cursor is malformed.']]);
            }
            $query->whereRaw('(published_at, id) < (?::timestamptz, ?)', [$ts, (int) $cursor[1]]);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'data' => FeedItemResource::collection($page),
            'meta' => [
                'scope' => 'global',
                'pagination' => [
                    'next_cursor' => ($hasMore && $last !== null)
                        ? KeysetCursor::encode('feed', [$last->published_at?->setTimezone('UTC')->format('Y-m-d H:i:s.u') ?? '', $last->id])
                        : null,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }
}
