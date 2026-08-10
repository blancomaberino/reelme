<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\MapPlacesRequest;
use App\Http\Requests\PlaceListingRequest;
use App\Http\Resources\InfluencerResource;
use App\Models\Influencer;
use App\Models\Place;
use App\Services\Map\MapViewport;
use Illuminate\Http\JsonResponse;

/**
 * Public influencer profiles (T-036, 03 §2.9). Influencer identities exist
 * independently of user accounts (auto-created by ingestion), so they are
 * always public; only published shares contribute to their promoted places.
 */
class InfluencerController extends Controller
{
    use PaginatesPlaces;

    public function show(Influencer $influencer): JsonResponse
    {
        $influencer->load('claimedBy');

        // ONE definition of "this influencer's places", shared with map() and
        // places() below — see PlaceQueryBuilder::promotedBy(). This was a
        // hand-rolled join that agreed with map()'s hand-rolled join only by
        // coincidence, and the profile ended up claiming "2 Lugares" one tap
        // above a map that said there were none.
        $count = Place::query()->publiclyVisible()->promotedBy($influencer)->count();

        $influencer->setAttribute('promoted_places_count', $count);

        $viewer = request()->user('sanctum');
        $follow = $viewer?->follows()->where('followee_type', 'influencer')->where('followee_id', $influencer->id)->first();

        return response()->json([
            'data' => new InfluencerResource($influencer),
            'meta' => [
                'viewer' => [
                    'following' => $follow !== null,
                    'follow_id' => $follow !== null ? (string) $follow->id : null,
                ],
            ],
        ]);
    }

    /**
     * Every visible place with a place_source tracing to this influencer's
     * posts on published shares — same pin/cluster shape as GET /map/places.
     */
    public function map(MapPlacesRequest $request, Influencer $influencer, MapViewport $viewport): JsonResponse
    {
        return $viewport->respond($request, fn ($q) => $q->promotedBy($influencer));
    }

    /**
     * The influencer's places as a LIST — the sibling of
     * `GET /users/{user}/places`, and the endpoint the mobile map screen
     * actually wants.
     *
     * The viewport route above cannot serve that screen: it needs a bbox, and
     * "everywhere this creator has posted" is not a viewport. The mobile hook
     * tried to express it as a whole-globe bbox, which the request rejects
     * twice over (wrong parameter shape, and a span the geography cast will not
     * take) — so every influencer map showed "no places from this creator".
     * A list has no viewport to get wrong.
     */
    public function places(PlaceListingRequest $request, Influencer $influencer): JsonResponse
    {
        return $this->placeListResponse(
            Place::query()->publiclyVisible()->promotedBy($influencer),
            $request,
        );
    }
}
