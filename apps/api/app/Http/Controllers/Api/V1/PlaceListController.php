<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceListRequest;
use App\Http\Requests\PublicListShowRequest;
use App\Http\Resources\PlaceListDetailResource;
use App\Http\Resources\PlaceListResource;
use App\Models\Place;
use App\Models\PlaceList;
use Illuminate\Database\Eloquent\Builder;
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
        // `?contains={placeId}` flags which lists already hold a place — powers
        // the mobile "save to list" picker without N per-list fetches.
        $placeId = $request->integer('contains');

        $lists = PlaceList::query()
            ->where('user_id', $request->user()->id)
            ->withCount('items')
            ->when($placeId > 0, fn ($q) => $q->withExists([
                'items as contains' => fn ($sub) => $sub->where('place_id', $placeId),
            ]))
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

    /**
     * Public read of a shared list (T-063), bound by `public_slug`. The privacy
     * gate is in PublicListShowRequest::authorize() (private → 404, no oracle).
     * Only publicly-visible places are exposed — a hidden/merged place a user
     * saved never leaks through someone else's shared list.
     */
    public function publicShow(PublicListShowRequest $request, PlaceList $list): JsonResponse
    {
        return response()->json([
            'data' => new PlaceListDetailResource($this->loadWithVisiblePlaces($list)),
            'meta' => (object) [],
        ]);
    }

    /**
     * Clone a public list into the caller's own lists (T-063). The source is
     * addressed by its `public_slug`; a private/missing source 404s (no oracle).
     * The copy is private (no public_slug) and carries only visible places.
     */
    public function copy(Request $request, string $slug): JsonResponse
    {
        $source = PlaceList::query()
            ->where('public_slug', $slug)
            ->where('is_public', true)
            ->first();
        abort_if($source === null, 404);

        $list = new PlaceList(['name' => $source->name]);
        $list->user_id = (int) $request->user()->id;
        $list->save();

        // Preserve order; skip any place that isn't publicly visible.
        $position = 0;
        $source->items()
            ->whereHas('place', function ($q): void {
                /** @var Builder<Place> $q */
                $q->publiclyVisible();
            })
            ->orderBy('position')->orderBy('id')
            ->each(function ($item) use ($list, &$position): void {
                $list->items()->create([
                    'place_id' => $item->place_id,
                    'note' => $item->note,
                    'position' => $position++,
                ]);
            });
        $list->touch();

        return response()->json([
            'data' => new PlaceListDetailResource($this->loadWithPlaces($list)),
            'meta' => (object) [],
        ], 201);
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

    /**
     * Like loadWithPlaces, but constrains the eager-loaded place to publicly
     * visible ones — an item whose place is hidden/merged resolves to a null
     * place and is then dropped, so the public read never exposes it.
     */
    private function loadWithVisiblePlaces(PlaceList $list): PlaceList
    {
        $list->load([
            'user',
            'items' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'items.place' => fn ($q) => $q->publiclyVisible()->select('places.*')
                ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng'),
        ]);
        $list->setRelation('items', $list->items->filter(fn ($item) => $item->place !== null)->values());

        return $list;
    }
}
