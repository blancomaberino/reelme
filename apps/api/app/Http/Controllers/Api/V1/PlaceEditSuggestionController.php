<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimStatus;
use App\Enums\PlaceStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Places\SuggestPlaceEditRequest;
use App\Http\Resources\PlaceEditSuggestionResource;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\PlaceEditSuggestion;
use App\Models\User;
use App\Services\Places\PlaceSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggested edits to a place's business info (T-083).
 *
 * `store` is open to every signed-in user — the correction box. Whether it
 * applies immediately or queues is decided by {@see User::ownsPlace()} inside
 * the service, not here: authorization would be the wrong tool, because a
 * non-owner is not forbidden, they are moderated.
 */
class PlaceEditSuggestionController extends Controller
{
    /** How many pending proposals an operator's screen loads at once. */
    private const VENUE_LIMIT = 100;

    /** Propose a correction. Applies immediately for a verified operator. */
    public function store(
        SuggestPlaceEditRequest $request,
        Place $place,
        PlaceSuggestionService $suggestions,
    ): JsonResponse {
        // Same guard as the other place sub-resources: a merged tombstone or a
        // moderated-off place is not something to collect corrections about, and
        // approving one would write a row no surface renders.
        abort_unless(
            $place->merged_into_place_id === null
            && in_array($place->status, [PlaceStatus::Pending, PlaceStatus::Active], true),
            404,
        );

        $suggestion = $suggestions->submit($place, $this->user($request), $request->patch(), $request->note());

        return ApiResponse::item(new PlaceEditSuggestionResource($suggestion), status: 201);
    }

    /**
     * Proposals still waiting on a moderator, across the venues the caller
     * operates — the operator's read-only view of "what are people telling us
     * about our own listing".
     *
     * Scoped by verified claim on every call (never by `is_restaurant_owner`,
     * which says only that the user operates SOMETHING): an operator sees the
     * venues they hold a claim on, and a revoked claim revokes this with it.
     */
    public function forMyVenues(Request $request): JsonResponse
    {
        $venueIds = PlaceClaim::query()
            ->where('user_id', $this->user($request)->id)
            ->where('status', ClaimStatus::Verified)
            ->pluck('place_id');

        if ($venueIds->isEmpty()) {
            return ApiResponse::collection([]);
        }

        $suggestions = PlaceEditSuggestion::query()
            ->pending()
            ->whereIn('place_id', $venueIds)
            ->with('place:id,name,slug')
            ->orderByDesc('id')
            ->limit(self::VENUE_LIMIT)
            ->get();

        return ApiResponse::collection(PlaceEditSuggestionResource::collection($suggestions));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
