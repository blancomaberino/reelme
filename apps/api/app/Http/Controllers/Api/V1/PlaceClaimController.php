<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceClaimMethod;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Places\StartPlaceClaimRequest;
use App\Http\Requests\Places\VerifyPlaceClaimRequest;
use App\Http\Resources\PlaceClaimResource;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use App\Services\Places\PlaceClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restaurant-owner claiming (T-041, 06 §2.1).
 *
 * Every route acts on the authenticated user's OWN claim for a place — the user
 * is never a parameter — so there is no object to fail to authorize. `show`
 * returns only the caller's claim, which is why a claimant cannot learn whether
 * someone else has one pending.
 */
class PlaceClaimController extends Controller
{
    /** The caller's own claim on this place, if any. */
    public function show(Request $request, Place $place): JsonResponse
    {
        $claim = PlaceClaim::query()
            ->where('place_id', $place->id)
            ->where('user_id', $this->user($request)->id)
            ->latest('id')
            ->first();

        return ApiResponse::item($claim === null ? null : new PlaceClaimResource($claim));
    }

    /** Start a claim by one of the three methods. */
    public function store(StartPlaceClaimRequest $request, Place $place, PlaceClaimService $claims): JsonResponse
    {
        $method = PlaceClaimMethod::from((string) $request->string('method'));
        $claim = $claims->start($place, $this->user($request), $method);

        return ApiResponse::item(new PlaceClaimResource($claim), status: 201);
    }

    /**
     * Complete an automatic claim.
     *
     * `phone` submits the code; `website` asks the backend to go look. A
     * `document` claim has nothing to verify from here — it waits on an admin —
     * which is enforced by the request rules rather than falling through to a
     * confusing "no pending claim".
     */
    public function verify(VerifyPlaceClaimRequest $request, Place $place, PlaceClaimService $claims): JsonResponse
    {
        $user = $this->user($request);

        $claim = PlaceClaimMethod::from((string) $request->string('method')) === PlaceClaimMethod::Phone
            ? $claims->verifyPhone($place, $user, (string) $request->string('code'))
            : $claims->verifyWebsite($place, $user);

        return ApiResponse::item(new PlaceClaimResource($claim));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
