<?php

namespace App\Http\Controllers\Api\V1;

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
    public function show(Place $place): JsonResponse
    {
        $place->load(['sources.sourcePost.influencer', 'reviews']);

        return response()->json([
            'data' => new PlaceResource($place),
            'meta' => (object) [],
        ]);
    }
}
