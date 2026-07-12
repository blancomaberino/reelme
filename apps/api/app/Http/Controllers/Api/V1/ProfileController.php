<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileMapRequest;
use App\Http\Requests\ProfileShowRequest;
use App\Http\Resources\FeedItemResource;
use App\Http\Resources\PublicUserResource;
use App\Models\Place;
use App\Models\User;
use App\Services\Feed\PublishedShareFeed;
use App\Services\Map\MapViewport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public user profiles (T-036, 03 §2.9). Only published content is exposed;
 * a private (`is_public = false`) profile 404s for everyone but its owner —
 * never 403, which would leak existence. The share list is the same
 * published-share feed constrained to the subject.
 */
class ProfileController extends Controller
{
    public function show(ProfileShowRequest $request, User $user, PublishedShareFeed $feed): JsonResponse
    {
        $this->assertViewable($request, $user);

        $limit = $request->limit();
        $page = $feed->paginate(
            'profile-shares',
            $request->validated('cursor'),
            $limit,
            fn ($q) => $q->where('shares.user_id', $user->id),
        );

        // Same constraints as the listed shares (incl. place visibility) so the
        // counter can never disagree with the list.
        $user->loadCount(['shares as published_shares_count' => fn ($q) => $q
            ->where('status', ShareStatus::Published)
            ->whereNotNull('published_place_source_id')
            ->whereHas('publishedPlaceSource.place', function ($p) {
                /** @var Builder<Place> $p */
                $p->publiclyVisible();
            })]);

        // Viewer-relative state (demo follow button / mobile): outside the
        // contract-pinned profile object on purpose.
        $viewer = $request->user('sanctum');
        $follow = $viewer?->follows()->where('followee_type', 'user')->where('followee_id', $user->id)->first();

        return response()->json([
            'data' => [
                'profile' => new PublicUserResource($user),
                'shares' => FeedItemResource::collection($page['items']),
            ],
            'meta' => [
                'viewer' => [
                    'following' => $follow !== null,
                    'follow_id' => $follow !== null ? (string) $follow->id : null,
                ],
                'pagination' => [
                    'next_cursor' => $page['next_cursor'],
                    'prev_cursor' => null,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    /**
     * The user's public map: places evidenced by their PUBLISHED shares only —
     * same pin/cluster shape as GET /map/places.
     */
    public function map(ProfileMapRequest $request, User $user, MapViewport $viewport): JsonResponse
    {
        $this->assertViewable($request, $user);

        return $viewport->respond($request, fn ($q) => $q->whereHas(
            'sources.share',
            fn ($s) => $s->where('user_id', $user->id)
                ->where('status', ShareStatus::Published),
        ));
    }

    /** Private profiles 404 for everyone but their owner (no existence leak). */
    private function assertViewable(Request $request, User $user): void
    {
        if ($user->is_public) {
            return;
        }

        abort_unless($request->user('sanctum')?->id === $user->id, 404);
    }
}
