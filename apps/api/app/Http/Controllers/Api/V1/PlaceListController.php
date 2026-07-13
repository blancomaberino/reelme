<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListRequest;
use App\Http\Resources\PlaceListDetailResource;
use App\Http\Resources\PlaceListResource;
use App\Models\Place;
use App\Models\PlaceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal place collections (T-062). All routes are owner-scoped: a list that
 * isn't the caller's 404s (never 403 — no existence oracle). The public share
 * read lives in T-063.
 */
class PlaceListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lists = PlaceList::query()
            ->where('user_id', $request->user()->id)
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => PlaceListResource::collection($lists),
            'meta' => (object) [],
        ]);
    }

    public function store(PlaceListRequest $request): JsonResponse
    {
        $list = new PlaceList($request->safe()->only(['name', 'is_public']));
        $list->user_id = (int) $request->user()->id;
        $list->save();

        return response()->json([
            'data' => new PlaceListResource($list->loadCount('items')),
            'meta' => (object) [],
        ], 201);
    }

    public function show(Request $request, PlaceList $list): JsonResponse
    {
        $this->assertOwner($request, $list);

        return response()->json([
            'data' => new PlaceListDetailResource($this->loadWithPlaces($list)),
            'meta' => (object) [],
        ]);
    }

    public function update(PlaceListRequest $request, PlaceList $list): JsonResponse
    {
        $this->assertOwner($request, $list);

        $list->fill($request->safe()->only(['name', 'is_public']))->save();

        return response()->json([
            'data' => new PlaceListResource($list->loadCount('items')),
            'meta' => (object) [],
        ]);
    }

    public function destroy(Request $request, PlaceList $list): JsonResponse
    {
        $this->assertOwner($request, $list);
        $list->delete();

        return response()->json(['data' => null, 'meta' => (object) []]);
    }

    /** Add a place to the list (idempotent). */
    public function addPlace(Request $request, PlaceList $list, Place $place): JsonResponse
    {
        $this->assertOwner($request, $list);

        $note = $request->string('note')->trim()->value();
        $item = $list->items()->firstOrCreate(
            ['place_id' => $place->id],
            ['note' => $note !== '' ? $note : null, 'position' => (int) $list->items()->max('position') + 1],
        );
        $list->touch();

        return response()->json([
            'data' => new PlaceListDetailResource($this->loadWithPlaces($list)),
            'meta' => (object) [],
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    /** Remove a place from the list. */
    public function removePlace(Request $request, PlaceList $list, Place $place): JsonResponse
    {
        $this->assertOwner($request, $list);
        $list->items()->where('place_id', $place->id)->delete();
        $list->touch();

        return response()->json([
            'data' => new PlaceListDetailResource($this->loadWithPlaces($list)),
            'meta' => (object) [],
        ]);
    }

    /** A list not owned by the caller is indistinguishable from a missing one. */
    private function assertOwner(Request $request, PlaceList $list): void
    {
        abort_unless((int) $list->user_id === (int) $request->user()->id, 404);
    }

    /** Load the list's items + each place with lat/lng, ordered by position. */
    private function loadWithPlaces(PlaceList $list): PlaceList
    {
        return $list->load([
            'user',
            'items' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'items.place' => fn ($q) => $q->select('places.*')
                ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng'),
        ]);
    }
}
