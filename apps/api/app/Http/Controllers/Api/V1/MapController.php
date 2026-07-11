<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MapPlacesRequest;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Map read path (T-029, 03 §3.3): `GET /map/places` returns server-clustered
 * pins for a viewport bbox. Below zoom 15 points are grid-clustered
 * (`ST_SnapToGrid`); at/above 15 raw pins are returned.
 *
 * The bbox predicate uses `&&` on the `geography` column so the GIST index is
 * hit; `ST_SnapToGrid` needs a `geometry` cast, done inside the CTE only.
 *
 * Deviation from the M2 spec's "active only": a first auto-published source
 * leaves the place `pending` (unverified but, per 02 §3.8 / T-023, on the map
 * immediately). We include `pending` + `active` and expose `status` so the
 * client can style unverified pins; `merged`/tombstoned never appear.
 */
class MapController extends Controller
{
    private const CLUSTER_ZOOM_CUTOFF = 15;

    private const PIN_CAP = 300;

    /** Max grid cells returned (biggest clusters first) — bounds an oversized-bbox request. */
    private const CELL_CAP = 400;

    /** Cells ≈ 60–80px: cell = 360 / (2^zoom * k). */
    private const GRID_K = 3;

    public function places(MapPlacesRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $filter = (string) ($request->validated('filter') ?? 'all');

        // `following`/`mine` are user-scoped — require auth.
        if (in_array($filter, ['following', 'mine'], true) && $user === null) {
            abort(401, 'Authentication is required for this filter.');
        }

        $bbox = [
            'minLng' => (float) $request->validated('minLng'),
            'minLat' => (float) $request->validated('minLat'),
            'maxLng' => (float) $request->validated('maxLng'),
            'maxLat' => (float) $request->validated('maxLat'),
        ];
        $zoom = (int) $request->validated('zoom');

        // `following` is a validated auth-required stub until T-037.
        if ($filter === 'following') {
            return response()->json([
                'data' => ['pins' => [], 'clusters' => []],
                'meta' => ['zoom' => $zoom, 'total_in_bbox' => 0, 'clustered' => false, 'filter' => 'following'],
            ]);
        }

        $total = $this->baseQuery($request, $bbox, $filter, $user?->id)->count();

        return $zoom >= self::CLUSTER_ZOOM_CUTOFF
            ? $this->pinsResponse($request, $bbox, $filter, $user?->id, $zoom, $total)
            : $this->clusteredResponse($request, $bbox, $filter, $user?->id, $zoom, $total);
    }

    /**
     * The filtered, bbox-scoped base query over visible places. Kept as a builder
     * so both the count and the pin/cluster paths share one definition.
     *
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     * @return Builder<Place>
     */
    private function baseQuery(MapPlacesRequest $request, array $bbox, string $filter, ?int $userId): Builder
    {
        $query = Place::query()
            ->publiclyVisible()
            ->whereRaw(
                'location && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography',
                [$bbox['minLng'], $bbox['minLat'], $bbox['maxLng'], $bbox['maxLat']],
            );

        if ($cuisine = $request->validated('cuisine')) {
            $query->where('cuisine_primary', $cuisine);
        }
        if ($price = $request->validated('price_range')) {
            $query->where('price_range', (int) $price);
        }

        // tags[] pivot + Tag model land in T-031 — validated, no-op until then.
        $tags = $request->validated('tags');
        if (is_array($tags)) {
            $query->anyTagSlug($tags);
        }

        if ($filter === 'mine' && $userId !== null) {
            $query->whereHas('sources.share', fn ($q) => $q->where('user_id', $userId));
        }

        return $query;
    }

    /**
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     */
    private function pinsResponse(MapPlacesRequest $request, array $bbox, string $filter, ?int $userId, int $zoom, int $total): JsonResponse
    {
        $places = $this->baseQuery($request, $bbox, $filter, $userId)
            ->select('*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->with(['primarySource.sourcePost.influencer'])
            ->orderByDesc('shares_count')
            ->limit(self::PIN_CAP + 1)
            ->get();

        $truncated = $places->count() > self::PIN_CAP;
        $pins = $places->take(self::PIN_CAP)->map(fn (Place $p) => $this->pin($p))->all();

        return response()->json([
            'data' => ['pins' => $pins, 'clusters' => []],
            'meta' => array_filter([
                'zoom' => $zoom,
                'total_in_bbox' => $total,
                'clustered' => false,
                'truncated' => $truncated ?: null,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     */
    private function clusteredResponse(MapPlacesRequest $request, array $bbox, string $filter, ?int $userId, int $zoom, int $total): JsonResponse
    {
        $cell = 360.0 / ((2 ** $zoom) * self::GRID_K);

        // Aggregate the (already filtered) places onto a grid. Reuse the base
        // builder's SQL + bindings so filters apply identically.
        $base = $this->baseQuery($request, $bbox, $filter, $userId)->select('id')->addSelect(DB::raw('location::geometry AS geom'));
        $sql = $base->toSql();
        $bindings = $base->getBindings();

        // Cap the number of cells (densest first) so an oversized bbox at low zoom
        // can't force a full-table aggregation + singleton re-fetch.
        $rows = DB::select(
            "WITH in_bbox AS ({$sql})
             SELECT ST_X(ST_SnapToGrid(geom, ?)) AS cell_x,
                    ST_Y(ST_SnapToGrid(geom, ?)) AS cell_y,
                    count(*) AS count,
                    min(id) AS sample_id,
                    ST_Y(ST_Centroid(ST_Collect(geom))) AS lat,
                    ST_X(ST_Centroid(ST_Collect(geom))) AS lng,
                    ST_XMin(ST_Extent(geom)) AS min_lng,
                    ST_YMin(ST_Extent(geom)) AS min_lat,
                    ST_XMax(ST_Extent(geom)) AS max_lng,
                    ST_YMax(ST_Extent(geom)) AS max_lat
             FROM in_bbox
             GROUP BY cell_x, cell_y
             ORDER BY count DESC
             LIMIT ?",
            [...$bindings, $cell, $cell, self::CELL_CAP + 1],
        );

        $truncated = count($rows) > self::CELL_CAP;
        $rows = array_slice($rows, 0, self::CELL_CAP);

        // Singleton cells become real pins (fetch their attributes); multi-place
        // cells become clusters.
        $singletonIds = [];
        $clusters = [];
        foreach ($rows as $row) {
            if ((int) $row->count === 1) {
                $singletonIds[] = (int) $row->sample_id;

                continue;
            }
            $cellX = (int) floor(((float) $row->cell_x) / $cell);
            $cellY = (int) floor(((float) $row->cell_y) / $cell);
            $clusters[] = [
                'type' => 'cluster',
                'cluster_id' => "{$zoom}:{$cellX}:{$cellY}",
                'lat' => round((float) $row->lat, 6),
                'lng' => round((float) $row->lng, 6),
                'count' => (int) $row->count,
                'expand' => ['bbox' => [
                    round((float) $row->min_lng, 6), round((float) $row->min_lat, 6),
                    round((float) $row->max_lng, 6), round((float) $row->max_lat, 6),
                ]],
            ];
        }

        $pins = [];
        if ($singletonIds !== []) {
            $pins = Place::query()
                ->whereIn('id', $singletonIds)
                ->select('*')
                ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
                ->with(['primarySource.sourcePost.influencer'])
                ->get()
                ->map(fn (Place $p) => $this->pin($p))
                ->all();
        }

        return response()->json([
            'data' => ['pins' => $pins, 'clusters' => $clusters],
            'meta' => array_filter([
                'zoom' => $zoom,
                'total_in_bbox' => $total,
                'clustered' => true,
                'truncated' => $truncated ?: null,
            ], fn ($v) => $v !== null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pin(Place $place): array
    {
        $influencer = $place->primarySource?->sourcePost?->influencer;

        return [
            'type' => 'place',
            'id' => (string) $place->id,
            'name' => $place->name,
            'lat' => round((float) $place->getAttribute('lat'), 6),
            'lng' => round((float) $place->getAttribute('lng'), 6),
            'category' => $place->cuisine_primary,
            'city' => $place->city,
            'price_range' => $place->price_range,
            'status' => $place->status->value,
            'tags' => [], // populated in T-031
            'source_count' => $place->shares_count,
            'has_active_offer' => false, // M4
            'top_influencer' => $influencer === null ? null : [
                'handle' => $influencer->handle,
                'display_name' => $influencer->display_name,
            ],
        ];
    }
}
