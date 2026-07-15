<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MapPlacesRequest;
use App\Models\PlaceList;
use App\Services\Map\MapViewport;
use Illuminate\Http\JsonResponse;

/**
 * Map read path (T-029, 03 §3.3): `GET /map/places` returns server-clustered
 * pins for a viewport bbox. The pin/cluster engine lives in
 * {@see MapViewport} (shared with the T-036 profile/influencer maps).
 *
 * Deviation from the M2 spec's "active only": a first auto-published source
 * leaves the place `pending` (unverified but, per 02 §3.8 / T-023, on the map
 * immediately). We include `pending` + `active` and expose `status` so the
 * client can style unverified pins; `merged`/tombstoned never appear.
 */
class MapController extends Controller
{
    public function places(MapPlacesRequest $request, MapViewport $viewport): JsonResponse
    {
        $user = $request->user('sanctum');
        $filter = (string) ($request->validated('filter') ?? 'all');
        $listId = $request->integer('list');

        // `following`/`mine` and the list filter are user-scoped — require auth.
        if ((in_array($filter, ['following', 'mine'], true) || $listId > 0) && $user === null) {
            abort(401, 'Authentication is required for this filter.');
        }

        $userId = $user?->id;

        // An owned list restricts the map to its places (404 if not the caller's).
        $listConstraint = null;
        if ($listId > 0) {
            $list = PlaceList::query()->where('id', $listId)->where('user_id', $userId)->first();
            abort_if($list === null, 404);
            $listConstraint = fn ($q) => $q->whereIn(
                'places.id',
                fn ($sub) => $sub->select('place_id')->from('place_list_items')->where('place_list_id', $listId),
            );
        }

        $filterConstraint = match ($filter) {
            // Places traceable to followed users/influencers (T-037). $user is
            // non-null here — guarded by the 401 above.
            'following' => fn ($q) => $q->followedBy($user),
            // The personal collection (T-071): shared ∪ saved, minus soft-hidden.
            // $user is non-null here — guarded by the 401 above.
            'mine' => fn ($q) => $q->mine($user),
            default => null,
        };

        // Compose the list + filter constraints (both are ANDed onto the base).
        $constrain = null;
        if ($listConstraint !== null || $filterConstraint !== null) {
            $constrain = function ($q) use ($listConstraint, $filterConstraint): void {
                if ($listConstraint !== null) {
                    $listConstraint($q);
                }
                if ($filterConstraint !== null) {
                    $filterConstraint($q);
                }
            };
        }

        return $viewport->respond($request, $constrain);
    }
}
