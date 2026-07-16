<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareStatus;
use App\Http\Controllers\Api\V1\Concerns\PaginatesPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListingRequest;
use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceListItem;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
     * Remove a place from my collection (T-071/T-073). `mode=hide` (default) is a
     * reversible per-place soft-hide of the pin — it stays in any lists it's in,
     * and only the aggregate map/"my places" hide it; re-sharing or re-saving
     * un-hides it. `mode=full` is permanent — it deletes my contribution (my
     * place_sources, and any of my shares thereby emptied) and un-saves it from
     * all my lists, so the place is fully out of my collection (re-share to get
     * it back). Removing from ONE list is a separate action (DELETE /me/lists/…).
     */
    public function destroy(Request $request, Place $place): Response
    {
        $user = $request->user();
        $mode = (string) ($request->validate([
            'mode' => ['nullable', Rule::in(['hide', 'full'])],
        ])['mode'] ?? 'hide');

        if ($mode === 'full') {
            $this->fullyRemove($user, $place);
        } else {
            HiddenPlace::firstOrCreate(['user_id' => $user->id, 'place_id' => $place->id]);
        }

        return response()->noContent();
    }

    /**
     * Permanently drop the caller's connection to a place: delete their
     * place_sources to it (per-place, so a multi-place post's siblings stay),
     * delete any of their shares left with no places, un-save from all their
     * lists, and clear any hide. Then recompute the (canonical) place's counters.
     */
    private function fullyRemove(User $user, Place $place): void
    {
        DB::transaction(function () use ($user, $place): void {
            $mySources = PlaceSource::query()
                ->where('place_id', $place->id)
                ->whereHas('share', fn ($q) => $q->where('user_id', $user->id))
                ->get();
            $shareIds = $mySources->pluck('share_id')->unique();

            // published_place_source_id is nullOnDelete, so this can't dangle it.
            PlaceSource::query()->whereIn('id', $mySources->pluck('id'))->delete();

            // A share left with no places (e.g. a single-place share) is fully gone.
            Share::query()->whereIn('id', $shareIds)->where('user_id', $user->id)
                ->whereDoesntHave('placeSources')->delete();

            PlaceListItem::query()->where('place_id', $place->id)
                ->whereHas('list', fn ($q) => $q->where('user_id', $user->id))->delete();

            HiddenPlace::where('user_id', $user->id)->where('place_id', $place->id)->delete();
        });

        // Counters reflect the remaining published sources (other users may keep it).
        if (($fresh = $place->fresh()) !== null) {
            $fresh->shares_count = $fresh->sources()->whereNotNull('published_at')->count();
            $fresh->save();
        }
    }
}
