<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Models\Place;
use Illuminate\Http\JsonResponse;

/**
 * Public place detail (03-api-design §3.3): `GET /places/{place}` returns the full
 * pin — aggregated discovery tags, contributing sources, Google + native ratings.
 * Route-model binding 404s a missing id; no auth (same public surface as the map).
 */
class PlaceController extends Controller
{
    /** Cap the serialized/aggregated sources so a very popular place stays bounded. */
    private const SOURCE_CAP = 24;

    public function show(Place $place): JsonResponse
    {
        // Merged/tombstoned places are hidden from every public surface (the map
        // filters them) — don't expose them by direct id either.
        abort_if($place->merged_into_place_id !== null || $place->status === PlaceStatus::Merged, 404);

        $place->load([
            'sources' => fn ($q) => $q->with('sourcePost.influencer')
                ->orderByDesc('is_primary')->orderBy('id')->limit(self::SOURCE_CAP),
            'reviews',
        ]);

        return response()->json([
            'data' => new PlaceResource($place),
            'meta' => (object) [],
        ]);
    }
}
