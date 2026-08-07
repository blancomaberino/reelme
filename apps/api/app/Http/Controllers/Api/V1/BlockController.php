<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserSummaryResource;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Moderation\BlockUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Blocking another account (T-054, IR-6 / Apple Guideline 1.2).
 *
 * A UGC app must let a person stop someone else's content reaching them without
 * waiting on a moderator — reporting alone is not enough, because reporting is
 * a request and blocking is a decision.
 *
 * The list endpoint exists so the block is REVERSIBLE from inside the app. A
 * block you cannot find again is one you cannot lift, and the settings screen
 * is the only place a user can see who they have blocked (a blocked profile is
 * a 404 for them, by design).
 */
class BlockController extends Controller
{
    public function __construct(private readonly BlockUsers $blocks) {}

    /** Who this user has blocked, newest first. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        $blocked = User::query()
            ->whereIn('id', UserBlock::query()->where('blocker_id', $me->id)->select('blocked_id'))
            ->orderByDesc('id')
            ->get();

        return ApiResponse::collection(UserSummaryResource::collection($blocked));
    }

    /**
     * Block `{user}`.
     *
     * 204 rather than 201: the client's next move is to leave the screen (the
     * profile it was on is now a 404 for them), and there is nothing in the row
     * worth sending back.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        // Blocking yourself would empty your own feed and 404 your own profile.
        // A CHECK constraint backs this up in the schema — the failure mode is
        // bad enough that it should not depend on one guard.
        abort_if($me->id === $user->id, 422, 'You cannot block yourself.');

        $this->blocks->block($me, $user);

        return response()->json(status: 204);
    }

    /** Lift a block. Idempotent — unblocking someone who is not blocked is fine. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();

        $this->blocks->unblock($me, $user);

        return response()->json(status: 204);
    }
}
