<?php

namespace App\Services\Moderation;

use App\Models\Follow;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;

/**
 * Blocking, and everything it has to drag with it (T-054, IR-6 / Apple 1.2).
 *
 * The row is the easy part. What makes this feature real is the side effects:
 * a block that leaves the two accounts still following each other has not
 * stopped anything, and one that only hides content in a single direction
 * leaves the blocker visible to the person they blocked — which is the exact
 * situation blocking exists to end.
 *
 * WHAT IS DELIBERATELY *NOT* AFFECTED: places. A place is community data with
 * many contributing sources, and dropping a whole restaurant off the map
 * because one blocked account also shared it would punish the blocker. Their
 * ATTRIBUTION is hidden from the blocker's view (they never see whose share it
 * came from); the pin stays. Same reasoning as T-049's refusal to take down a
 * `source_post` shared between users.
 */
class BlockUsers
{
    /**
     * Block `$target` on behalf of `$blocker`, and sever the relationship.
     *
     * Idempotent: blocking someone already blocked is a no-op, not a 409. The
     * client cannot always know the current state (a stale profile screen, a
     * double tap), and a "you already blocked them" error is a worse answer
     * than silently agreeing.
     */
    public function block(User $blocker, User $target): UserBlock
    {
        return DB::transaction(function () use ($blocker, $target): UserBlock {
            // `insertOrIgnore`, NOT create-and-catch. In Postgres a constraint
            // violation ABORTS the surrounding transaction: every statement
            // after the catch fails with 25P02, so the "idempotent" path took
            // the whole request down with a 500 — and only on Postgres, which
            // is exactly why this suite runs against Postgres and never sqlite.
            // ON CONFLICT DO NOTHING never raises, so there is nothing to
            // recover from and the block stays race-safe.
            UserBlock::query()->insertOrIgnore([
                'blocker_id' => $blocker->id,
                'blocked_id' => $target->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $block = UserBlock::query()
                ->where('blocker_id', $blocker->id)
                ->where('blocked_id', $target->id)
                ->firstOrFail();

            // BOTH directions. Leaving the blocked user following the blocker
            // means their new shares keep arriving in that person's followers
            // count and notifications — the follow edge is the subscription,
            // and blocking has to cancel it.
            $this->unfollowBothWays($blocker, $target);

            return $block;
        });
    }

    /**
     * Lift a block. Follows are NOT restored — they were severed, not paused,
     * and silently re-subscribing someone to an account they blocked would be
     * a surprising thing for the app to decide on their behalf.
     */
    public function unblock(User $blocker, User $target): void
    {
        UserBlock::query()
            ->where('blocker_id', $blocker->id)
            ->where('blocked_id', $target->id)
            ->delete();
    }

    /**
     * Is there a block in EITHER direction between these two?
     *
     * The question every read path actually needs. Whether the viewer blocked
     * them or they blocked the viewer, the answer for visibility is the same,
     * and code that checks only one direction is code that leaks content to
     * somebody who blocked its author.
     */
    public function betweenExists(?int $viewerId, int $otherId): bool
    {
        if ($viewerId === null || $viewerId === $otherId) {
            return false;
        }

        return UserBlock::query()
            ->where(fn ($q) => $q->where('blocker_id', $viewerId)->where('blocked_id', $otherId))
            ->orWhere(fn ($q) => $q->where('blocker_id', $otherId)->where('blocked_id', $viewerId))
            ->exists();
    }

    /**
     * Every user id invisible to `$viewerId`, in either direction.
     *
     * Returned as ids rather than applied as a scope so callers can use it in a
     * `whereNotIn` on whatever column holds the author — the feed keys on
     * `shares.user_id`, follower lists on the edge's own columns.
     *
     * @return list<int>
     */
    public function invisibleTo(?int $viewerId): array
    {
        if ($viewerId === null) {
            return [];
        }

        return UserBlock::query()
            ->where('blocker_id', $viewerId)
            ->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $viewerId)->pluck('blocker_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Sever the follow edges in both directions, keeping the DENORMALIZED
     * counters honest.
     *
     * `users.followers_count` / `following_count` are maintained by
     * FollowController on every follow and unfollow. Deleting edges here
     * without adjusting them would leave a profile reading "12 followers" over
     * a list of 11 — the counter is what a person sees, so a bulk delete that
     * skips it is a visible bug, not an internal one.
     *
     * The `> 0` guard is the same idiom FollowController uses: a counter that
     * has already drifted must not be driven negative by a correction.
     */
    private function unfollowBothWays(User $a, User $b): void
    {
        $morph = $a->getMorphClass();

        $edges = Follow::query()
            ->where(fn ($q) => $q->where('follower_user_id', $a->id)
                ->where('followee_type', $morph)->where('followee_id', $b->id))
            ->orWhere(fn ($q) => $q->where('follower_user_id', $b->id)
                ->where('followee_type', $morph)->where('followee_id', $a->id))
            ->get();

        if ($edges->isEmpty()) {
            return;
        }

        Follow::query()->whereKey($edges->pluck('id'))->delete();

        foreach ($edges as $edge) {
            User::query()->whereKey($edge->follower_user_id)
                ->where('following_count', '>', 0)->decrement('following_count');
            User::query()->whereKey($edge->followee_id)
                ->where('followers_count', '>', 0)->decrement('followers_count');
        }
    }
}
