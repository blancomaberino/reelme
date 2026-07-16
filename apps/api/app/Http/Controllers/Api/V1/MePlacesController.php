<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListingRequest;
use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            // and whether to show a "saved" marker.
            [
                // My latest published share to this place, if any (a hidden pin
                // is excluded from the result set entirely, so no per-share
                // dismissal check is needed here).
                [
                    '(select ps.share_id from place_sources ps '
                    .'join shares s on s.id = ps.share_id '
                    .'where ps.place_id = places.id and s.user_id = ? and s.status = ? '
                    .'order by s.id desc limit 1) as mine_share_id',
                    [$uid, ShareStatus::Published->value],
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

    /**
     * Remove a place from my personal collection (T-071) — the write side of the
     * "remove from my map" action. Idempotent and transactional: it soft-hides
     * THIS pin (per-place, so a multi-place post's siblings stay), and un-saves
     * it from all my lists. Re-sharing or re-saving the place un-hides it. A
     * place I have no connection to is still hidden (harmless no-op on read).
     */
    public function destroy(Request $request, Place $place): Response
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $place): void {
            HiddenPlace::firstOrCreate(['user_id' => $user->id, 'place_id' => $place->id]);

            PlaceListItem::query()
                ->where('place_id', $place->id)
                ->whereHas('list', fn ($q) => $q->where('user_id', $user->id))
                ->delete();
        });

        return response()->noContent();
    }
}
