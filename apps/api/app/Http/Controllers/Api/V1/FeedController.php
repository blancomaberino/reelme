<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeedRequest;
use App\Http\Resources\FeedItemResource;
use App\Services\Feed\PublishedShareFeed;
use Illuminate\Http\JsonResponse;

/**
 * Discovery feed (T-034, 03 §2.8): reverse-chronological published shares.
 * M2 ships `scope=global` (public); `scope=following` is an auth-required
 * stub with a stable response shape until T-037 wires follows. The query +
 * cursor engine lives in {@see PublishedShareFeed} (shared with the T-036
 * profile share lists).
 *
 * Place visibility matches every other public surface (pending + active,
 * never merged) — the spec's "active only" has the same documented
 * deviation as the map (T-029): a first auto-publish stays pending.
 */
class FeedController extends Controller
{
    public function index(FeedRequest $request, PublishedShareFeed $feed): JsonResponse
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

        $page = $feed->paginate('feed', $request->validated('cursor'), $limit);

        return response()->json([
            'data' => FeedItemResource::collection($page['items']),
            'meta' => [
                'scope' => 'global',
                'pagination' => [
                    'next_cursor' => $page['next_cursor'],
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }
}
