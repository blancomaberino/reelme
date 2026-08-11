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
use Illuminate\Support\Facades\DB;

/**
 * Public influencer profiles (T-036, 03 §2.9). Influencer identities exist
 * independently of user accounts (auto-created by ingestion), so they are
 * always public; only published shares contribute to their promoted places.
 */
class InfluencerController extends Controller
{
    use PaginatesPlaces;

    /**
     * The most places any view of an influencer will report or draw.
     *
     * ONE number for the counter, the list and the map — which is the whole
     * point. The first cut of this feature made the counter unbounded and the
     * list one 50-row page, so a prolific creator got "137 Lugares" over a list
     * of 50; capping the client at 200 without capping the counter just moved
     * the same contradiction to a higher threshold.
     *
     * A ceiling rather than "fetch everything" because the client pages this:
     * "follow the cursor until it ends" is an unbounded number of round trips
     * driven by data we do not control. 200 is comfortably above any real
     * creator today, and past it the list is a PREFIX of the truth rather than
     * a different number from it — `meta.places_capped` says so out loud.
     *
     * The mobile hook mirrors this value; both sides assert it.
     */
    public const PLACES_CAP = 200;

    public function show(Influencer $influencer): JsonResponse
    {
        $influencer->load('claimedBy');

        // ONE definition of "this influencer's places", shared with map() and
        // places() below — see PlaceQueryBuilder::promotedBy(). This was a
        // hand-rolled join that agreed with map()'s hand-rolled join only by
        // coincidence, and the profile ended up claiming "2 Lugares" one tap
        // above a map that said there were none.
        // Counted through a capped subquery so the DB stops at the ceiling
        // instead of scanning a prolific creator's whole back catalogue to
        // produce a number no view will ever show.
        $count = DB::query()
            ->fromSub(
                Place::query()->publiclyVisible()->promotedBy($influencer)
                    ->select('places.id')->limit(self::PLACES_CAP + 1),
                'capped',
            )
            ->count();

        $capped = $count > self::PLACES_CAP;
        $influencer->setAttribute('promoted_places_count', min($count, self::PLACES_CAP));

        $viewer = request()->user('sanctum');
        $follow = $viewer?->follows()->where('followee_type', 'influencer')->where('followee_id', $influencer->id)->first();

        return response()->json([
            'data' => new InfluencerResource($influencer),
            'meta' => [
                'viewer' => [
                    'following' => $follow !== null,
                    'follow_id' => $follow !== null ? (string) $follow->id : null,
                ],
                // Exposed, not just applied: the client pages the list itself
                // and has to know where to stop, and a UI showing "200 Lugares"
                // should be able to say "200+" when that is the truth.
                'places_cap' => self::PLACES_CAP,
                'places_capped' => $capped,
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
