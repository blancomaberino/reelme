<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MapPlacesRequest;
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

        // `following`/`mine` are user-scoped — require auth.
        if (in_array($filter, ['following', 'mine'], true) && $user === null) {
            abort(401, 'Authentication is required for this filter.');
        }

        // `following` is a validated auth-required stub until T-037.
        if ($filter === 'following') {
            return response()->json([
                'data' => ['pins' => [], 'clusters' => []],
                'meta' => [
                    'zoom' => (int) $request->validated('zoom'),
                    'total_in_bbox' => 0,
                    'clustered' => false,
                    'filter' => 'following',
                ],
            ]);
        }

        $userId = $user?->id;

        return $viewport->respond($request, $filter === 'mine' && $userId !== null
            ? fn ($q) => $q->whereHas('sources.share', fn ($s) => $s->where('user_id', $userId))
            : null);
    }
}
