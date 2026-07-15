<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListingRequest;
use App\Models\Place;
use Illuminate\Http\JsonResponse;

/**
 * The personal "my places" list (T-071, ADR-071) — `GET /me/places`. The
 * filterable list view (country, type, tags) of the same personal collection
 * the home map shows: places I shared (not soft-hidden) ∪ places I saved. This
 * replaces the removed global chronological feed as the app's browse surface.
 */
class MePlacesController extends Controller
{
    use PaginatesPlaces;

    public function index(PlaceListingRequest $request): JsonResponse
    {
        $user = $request->user();
        $uid = $user->id;

        return $this->placeListResponse(
            Place::query()->publiclyVisible()->mine($user),
            $request,
            // Per-row provenance so the client knows how each place is "mine"
            // and which remove action to offer (dismiss my share vs un-save).
            [
                // My live (published, not soft-hidden) share to this place, if any
                // — the id the "remove from my map" action soft-hides.
                [
                    '(select ps.share_id from place_sources ps '
                    .'join shares s on s.id = ps.share_id '
                    .'where ps.place_id = places.id and s.user_id = ? and s.status = ? '
                    .'and not exists (select 1 from feed_dismissals fd where fd.share_id = s.id and fd.user_id = ?) '
                    .'order by s.id desc limit 1) as mine_share_id',
                    [$uid, ShareStatus::Published->value, $uid],
                ],
                // Whether it sits in any of my lists.
                [
                    'exists (select 1 from place_list_items pli '
                    .'join place_lists pl on pl.id = pli.place_list_id '
                    .'where pli.place_id = places.id and pl.user_id = ?) as mine_saved',
                    [$uid],
                ],
            ],
        );
    }
}
