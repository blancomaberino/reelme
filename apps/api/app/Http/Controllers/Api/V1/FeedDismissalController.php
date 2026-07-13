<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Models\FeedDismissal;
use App\Models\Share;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Hide from my feed" (T-034 follow-up): a signed-in user dismisses a published
 * share so it drops out of their own feed. Non-destructive and reversible — the
 * place stays live for everyone else and on the map. The feed query
 * ({@see FeedController}) filters dismissed shares
 * out for the viewer.
 */
class FeedDismissalController extends Controller
{
    /** Hide a share from my feed (idempotent). */
    public function store(Request $request): JsonResponse
    {
        // Only a published (feed-visible) share can be hidden — this also keeps
        // the dismissals table free of junk rows for arbitrary share ids.
        $validated = $request->validate([
            'share_id' => ['required', 'integer', Rule::exists('shares', 'id')->where('status', ShareStatus::Published->value)],
        ]);

        $dismissal = FeedDismissal::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'share_id' => (int) $validated['share_id'],
        ]);

        return response()->json(
            ['data' => ['id' => (string) $dismissal->id], 'meta' => (object) []],
            $dismissal->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** Un-hide a share (route-model-bound by share id). Idempotent. */
    public function destroy(Request $request, Share $share): JsonResponse
    {
        FeedDismissal::query()
            ->where('user_id', $request->user()->id)
            ->where('share_id', $share->id)
            ->delete();

        return response()->json(['data' => null, 'meta' => (object) []], 200);
    }
}
