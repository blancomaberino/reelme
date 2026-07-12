<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InfluencerSummaryResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Follow;
use App\Models\Influencer;
use App\Models\User;
use App\Notifications\NewFollower;
use App\Support\KeysetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Follows (T-037, 03 §2.10): a user follows users or influencers. Counter
 * caches move inside the same transaction as the edge write; the DB unique
 * triple makes a concurrent double-submit unable to double-count.
 */
class FollowController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'followable_type' => ['required', Rule::in(['user', 'influencer'])],
            'followable_id' => ['required', 'integer', 'min:1'],
        ]);

        $me = $request->user();
        $type = (string) $validated['followable_type'];
        $id = (int) $validated['followable_id'];

        $followee = $this->resolveFollowee($request, $type, $id);

        // No self-follow — directly or via your own claimed influencer identity.
        $selfTarget = ($type === 'user' && $followee->id === $me->id)
            || ($followee instanceof Influencer && $followee->claimed_by_user_id === $me->id);
        abort_if($selfTarget, 422, 'You cannot follow yourself.');

        $existing = Follow::query()
            ->where('follower_user_id', $me->id)
            ->where('followee_type', $type)
            ->where('followee_id', $id)
            ->first();
        if ($existing !== null) {
            return response()->json([
                'data' => ['id' => (string) $existing->id],
                'meta' => (object) [],
            ], 409);
        }

        $follow = DB::transaction(function () use ($me, $type, $id, $followee) {
            $follow = Follow::query()->firstOrCreate([
                'follower_user_id' => $me->id,
                'followee_type' => $type,
                'followee_id' => $id,
            ]);
            if (! $follow->wasRecentlyCreated) {
                return $follow; // lost a concurrent race — counters already moved
            }

            User::query()->whereKey($me->id)->increment('following_count');
            if ($followee instanceof User) {
                User::query()->whereKey($followee->id)->increment('followers_count');
            } else {
                Influencer::query()->whereKey($followee->id)->increment('followers_count');
            }

            return $follow;
        });

        if ($follow->wasRecentlyCreated) {
            // Followed users hear about it; claimed influencers notify their
            // owner; unclaimed influencers have no inbox — silently fine.
            $notifiable = $followee instanceof User ? $followee : $followee->claimedBy;
            $notifiable?->notify(new NewFollower($me));
        }

        return response()->json([
            'data' => ['id' => (string) $follow->id],
            'meta' => (object) [],
        ], $follow->wasRecentlyCreated ? 201 : 409);
    }

    public function destroy(Request $request, Follow $follow): JsonResponse
    {
        abort_unless($follow->follower_user_id === $request->user()->id, 403);

        DB::transaction(function () use ($follow) {
            $follow->delete();

            User::query()->whereKey($follow->follower_user_id)
                ->where('following_count', '>', 0)->decrement('following_count');

            if ($follow->followee_type === 'user') {
                User::query()->whereKey($follow->followee_id)
                    ->where('followers_count', '>', 0)->decrement('followers_count');
            } else {
                Influencer::query()->whereKey($follow->followee_id)
                    ->where('followers_count', '>', 0)->decrement('followers_count');
            }
        });

        return response()->json(['data' => null, 'meta' => (object) []], 200);
    }

    /** Who I follow — cursor-paginated, followees serialized as summaries. */
    public function follows(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $cursor = KeysetCursor::decode($validated['cursor'] ?? null, 'me-follows', 1);

        $query = $request->user()->follows()->with('followee')->orderByDesc('id');
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return response()->json([
            'data' => $page->map(fn (Follow $f) => [
                'id' => (string) $f->id,
                'followable_type' => $f->followee_type,
                'followee' => match (true) {
                    $f->followee instanceof User => new UserSummaryResource($f->followee),
                    $f->followee instanceof Influencer => new InfluencerSummaryResource($f->followee),
                    default => null, // followee deleted since — edge is stale
                },
            ]),
            'meta' => [
                'pagination' => [
                    'next_cursor' => ($hasMore && $last !== null) ? KeysetCursor::encode('me-follows', [$last->id]) : null,
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /**
     * The target must exist AND — for users — pass the same public-visibility
     * rule as their profile (a private account is unfollowable and looks
     * nonexistent).
     */
    private function resolveFollowee(Request $request, string $type, int $id): User|Influencer
    {
        if ($type === 'user') {
            $user = User::query()->find($id);
            abort_if($user === null, 404);
            abort_unless($user->is_public || $user->id === $request->user()->id, 404);

            return $user;
        }

        $influencer = Influencer::query()->find($id);
        abort_if($influencer === null, 404);

        return $influencer;
    }
}
