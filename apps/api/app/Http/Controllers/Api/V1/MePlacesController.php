<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListingRequest;
use App\Models\FeedDismissal;
use App\Models\Place;
use App\Models\PlaceListItem;
use App\Models\Share;
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
                // My live (published, not soft-hidden) share to this place, if any.
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

    /**
     * Remove a place from my personal collection (T-071) — the write side of the
     * "remove from my map" action. Idempotent and transactional: it soft-hides
     * EVERY published share of mine that resolves to the place (so a place I
     * shared more than once fully drops, not just the latest), and un-saves it
     * from all my lists. A place I have no connection to is a no-op (204).
     */
    public function destroy(Request $request, Place $place): Response
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $place): void {
            $shareIds = Share::query()
                ->where('user_id', $user->id)
                ->where('status', ShareStatus::Published)
                ->whereHas('placeSources', fn ($q) => $q->where('place_id', $place->id))
                ->pluck('id');

            foreach ($shareIds as $shareId) {
                FeedDismissal::firstOrCreate(['user_id' => $user->id, 'share_id' => $shareId]);
            }

            PlaceListItem::query()
                ->where('place_id', $place->id)
                ->whereHas('list', fn ($q) => $q->where('user_id', $user->id))
                ->delete();
        });

        return response()->noContent();
    }
}
