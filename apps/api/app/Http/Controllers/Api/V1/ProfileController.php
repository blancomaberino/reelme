<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileMapRequest;
use App\Http\Requests\ProfilePlacesRequest;
use App\Http\Requests\ProfileShowRequest;
use App\Http\Resources\FeedItemResource;
use App\Http\Resources\InfluencerSummaryResource;
use App\Http\Resources\PlaceListResource;
use App\Http\Resources\PublicUserResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Builders\PlaceQueryBuilder;
use App\Models\Follow;
use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use App\Services\Feed\PublishedShareFeed;
use App\Services\Map\MapViewport;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use App\Support\Profiles\ProfileVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public user profiles (T-036, 03 §2.9). Only published content is exposed;
 * a private (`is_public = false`) profile 404s for everyone but its owner —
 * never 403, which would leak existence. The share list is the same
 * published-share feed constrained to the subject.
 */
class ProfileController extends Controller
{
    use PaginatesPlaces;

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
                /** @var PlaceQueryBuilder $p */
                $p->publiclyVisible();
            })]);

        // Viewer-relative state (demo follow button / mobile): outside the
        // contract-pinned profile object on purpose.
        $viewer = $request->user('sanctum');
        $follow = $viewer?->follows()->where('followee_type', 'user')->where('followee_id', $user->id)->first();

        $shares = KeysetPage::of($page['items'], $limit, $page['next_cursor']);

        return ApiResponse::page(
            [
                'profile' => new PublicUserResource($user),
                'shares' => FeedItemResource::collection($shares->items),
            ],
            $shares,
            [
                'viewer' => [
                    'following' => $follow !== null,
                    'follow_id' => $follow !== null ? (string) $follow->id : null,
                ],
            ],
        );
    }

    /**
     * The user's public map: places evidenced by their PUBLISHED shares only —
     * same pin/cluster shape as GET /map/places.
     */
    public function map(ProfileMapRequest $request, User $user, MapViewport $viewport): JsonResponse
    {
        $this->assertViewable($request, $user);

        return $viewport->respond($request, fn ($q) => $q->publishedBy($user));
    }

    /**
     * The user's public places as a filterable list (T-071) — the list view of
     * their map. Same subject constraint as {@see map()} (places evidenced by
     * their PUBLISHED shares), with the shared country/type/tags facets.
     */
    public function places(ProfilePlacesRequest $request, User $user): JsonResponse
    {
        // Privacy gate runs in ProfilePlacesRequest::authorize() (before param
        // validation) so an invalid facet can't become a 404-vs-422 oracle.
        $base = Place::query()->publiclyVisible()->publishedBy($user);

        return $this->placeListResponse($base, $request);
    }

    /**
     * The user's PUBLIC lists (T-071) — visiting a profile surfaces their public
     * Lists (T-062/T-063) alongside their map + places. Private lists never leak.
     */
    public function lists(Request $request, User $user): JsonResponse
    {
        $this->assertViewable($request, $user);

        // Bounded (a user with >100 public lists is truncated — acceptable; the
        // owner-lists CRUD is the paginated surface). `user` loaded so the
        // resource can attribute the (public) owner.
        $lists = PlaceList::query()
            ->where('user_id', $user->id)
            ->where('is_public', true)
            ->withCount('items')
            ->with('user')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        return ApiResponse::collection(PlaceListResource::collection($lists));
    }

    /**
     * The accounts that follow this user (T-039). Same private-profile 404 gate;
     * a follower who has since gone private is withheld (null) like everywhere
     * else, but their edge still paginates.
     */
    public function followers(Request $request, User $user): JsonResponse
    {
        $this->assertViewable($request, $user);

        [$limit, $cursor] = $this->followPageParams($request);
        $query = $user->followers()->with('follower')->orderByDesc('id');
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }
        $rows = $query->limit($limit + 1)->get();

        return $this->followPage($rows, $limit, fn (Follow $f): array => [
            'id' => (string) $f->id,
            'user' => ($f->follower && $f->follower->is_public) ? new UserSummaryResource($f->follower) : null,
        ]);
    }

    /**
     * The users + influencers this user follows (T-039). Same shape as
     * GET /me/follows, but for an arbitrary (viewable) profile.
     */
    public function following(Request $request, User $user): JsonResponse
    {
        $this->assertViewable($request, $user);

        [$limit, $cursor] = $this->followPageParams($request);
        $query = $user->follows()->with('followee')->orderByDesc('id');
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }
        $rows = $query->limit($limit + 1)->get();

        return $this->followPage($rows, $limit, fn (Follow $f): array => [
            'id' => (string) $f->id,
            'followable_type' => $f->followee_type,
            'followee' => match (true) {
                $f->followee instanceof User => $f->followee->is_public ? new UserSummaryResource($f->followee) : null,
                $f->followee instanceof Influencer => new InfluencerSummaryResource($f->followee),
                default => null,
            },
        ]);
    }

    /**
     * @return array{0: int, 1: list<int|float|string>|null}
     */
    private function followPageParams(Request $request): array
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ]);

        return [
            (int) ($validated['limit'] ?? 25),
            KeysetCursor::decode($validated['cursor'] ?? null, 'profile-follows', 1),
        ];
    }

    /**
     * @param  Collection<int, Follow>  $rows
     * @param  callable(Follow): array<string, mixed>  $map
     */
    private function followPage(Collection $rows, int $limit, callable $map): JsonResponse
    {
        // Both lists keyset on Follow.id desc, so one namespace serves both.
        $page = KeysetPage::fromRows($rows, $limit, 'profile-follows', fn (Follow $last) => [$last->id]);

        return ApiResponse::page($page->items->map($map), $page);
    }

    /** Private profiles 404 for everyone but their owner (no existence leak). */
    /**
     * One gate for every profile read path — see {@see ProfileVisibility} for
     * why the rule does not live here any more.
     */
    private function assertViewable(Request $request, User $user): void
    {
        ProfileVisibility::assert($request->user('sanctum'), $user);
    }
}
