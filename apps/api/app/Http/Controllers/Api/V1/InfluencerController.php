<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MapPlacesRequest;
use App\Http\Resources\InfluencerResource;
use App\Models\Influencer;
use App\Services\Map\MapViewport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Public influencer profiles (T-036, 03 §2.9). Influencer identities exist
 * independently of user accounts (auto-created by ingestion), so they are
 * always public; only published shares contribute to their promoted places.
 */
class InfluencerController extends Controller
{
    public function show(Influencer $influencer): JsonResponse
    {
        $influencer->load('claimedBy');

        // Distinct visible places reachable via this influencer's posts on
        // published shares.
        $count = DB::table('place_sources')
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->join('shares', 'shares.id', '=', 'place_sources.share_id')
            ->join('places', 'places.id', '=', 'place_sources.place_id')
            ->where('source_posts.influencer_id', $influencer->id)
            ->where('shares.status', ShareStatus::Published->value)
            ->whereIn('places.status', ['pending', 'active'])
            ->whereNull('places.merged_into_place_id')
            ->distinct('place_sources.place_id')
            ->count('place_sources.place_id');

        $influencer->setAttribute('promoted_places_count', $count);

        return response()->json([
            'data' => new InfluencerResource($influencer),
            'meta' => (object) [],
        ]);
    }

    /**
     * Every visible place with a place_source tracing to this influencer's
     * posts on published shares — same pin/cluster shape as GET /map/places.
     */
    public function map(MapPlacesRequest $request, Influencer $influencer, MapViewport $viewport): JsonResponse
    {
        return $viewport->respond($request, fn ($q) => $q->whereExists(fn ($sub) => $sub
            ->from('place_sources')
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->join('shares', 'shares.id', '=', 'place_sources.share_id')
            ->whereColumn('place_sources.place_id', 'places.id')
            ->where('source_posts.influencer_id', $influencer->id)
            ->where('shares.status', ShareStatus::Published->value)));
    }
}
