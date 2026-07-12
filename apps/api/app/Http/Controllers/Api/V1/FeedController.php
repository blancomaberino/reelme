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
        $scope = $request->scope();
        $constrain = null;

        if ($scope === 'following') {
            $me = $request->user('sanctum');
            abort_unless($me !== null, 401);

            // Shares BY followed users, or crediting followed influencers (T-037).
            $constrain = fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('shares.user_id', fn ($f) => $f->select('followee_id')->from('follows')
                    ->where('follower_user_id', $me->id)->where('followee_type', 'user'))
                ->orWhereIn('shares.source_post_id', fn ($p) => $p->select('source_posts.id')->from('source_posts')
                    ->whereIn('source_posts.influencer_id', fn ($f) => $f->select('followee_id')->from('follows')
                        ->where('follower_user_id', $me->id)->where('followee_type', 'influencer'))));
        }

        $page = $feed->paginate('feed', $request->validated('cursor'), $limit, $constrain);

        return response()->json([
            'data' => FeedItemResource::collection($page['items']),
            'meta' => [
                'scope' => $scope,
                'pagination' => [
                    'next_cursor' => $page['next_cursor'],
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }
}
