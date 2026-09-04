<?php

namespace App\Services\Map;

use App\Http\ApiResponse;
use App\Http\Requests\MapPlacesRequest;
use App\Http\Resources\Concerns\ResolvesThumbnail;
use App\Models\Builders\PlaceQueryBuilder;
use App\Models\Place;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Viewport → pins/clusters engine (extracted from MapController for T-036 so
 * the profile/influencer public maps answer with the exact same shape).
 * Below zoom 15 points are grid-clustered (`ST_SnapToGrid`, geometry cast in
 * the CTE only); at/above 15 raw pins are returned. The bbox predicate uses
 * `&&` on the `geography` column so the GIST index is hit.
 *
 * An optional `$constrain` closure scopes the visible-places base query (e.g.
 * "places evidenced by this user's published shares").
 */
class MapViewport
{
    use ResolvesThumbnail;

    private const CLUSTER_ZOOM_CUTOFF = 15;

    private const PIN_CAP = 300;

    /** Max grid cells returned (biggest clusters first) — bounds an oversized-bbox request. */
    private const CELL_CAP = 400;

    /** Cells ≈ 60–80px: cell = 360 / (2^zoom * k). */
    private const GRID_K = 3;

    /**
     * @param  (Closure(PlaceQueryBuilder): mixed)|null  $constrain
     */
    public function respond(MapPlacesRequest $request, ?Closure $constrain = null): JsonResponse
    {
        $bbox = [
            'minLng' => (float) $request->validated('minLng'),
            'minLat' => (float) $request->validated('minLat'),
            'maxLng' => (float) $request->validated('maxLng'),
            'maxLat' => (float) $request->validated('maxLat'),
        ];
        $zoom = (int) $request->validated('zoom');

        $total = $this->baseQuery($request, $bbox, $constrain)->count();

        return $zoom >= self::CLUSTER_ZOOM_CUTOFF
            ? $this->pinsResponse($request, $bbox, $constrain, $zoom, $total)
            : $this->clusteredResponse($request, $bbox, $constrain, $zoom, $total);
    }

    /**
     * The filtered, bbox-scoped base query over visible places. Kept as a builder
     * so both the count and the pin/cluster paths share one definition.
     *
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     * @param  (Closure(PlaceQueryBuilder): mixed)|null  $constrain
     */
    private function baseQuery(MapPlacesRequest $request, array $bbox, ?Closure $constrain): PlaceQueryBuilder
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

        $tags = $request->validated('tags');
        if (is_array($tags)) {
            $query->allTagSlugs($tags);
        }

        if (($card = (string) ($request->validated('card') ?? '')) !== '') {
            $query->withPaymentCard($card);
        }

        if (($dish = (string) ($request->validated('dish') ?? '')) !== '') {
            $query->servingDish($dish);
        }

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query;
    }

    /**
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     * @param  (Closure(PlaceQueryBuilder): mixed)|null  $constrain
     */
    private function pinsResponse(MapPlacesRequest $request, array $bbox, ?Closure $constrain, int $zoom, int $total): JsonResponse
    {
        $near = $request->nearPoint();
        $places = $this->selectPinFields($this->baseQuery($request, $bbox, $constrain), $near)
            ->orderByDesc('shares_count')
            ->limit(self::PIN_CAP + 1)
            ->get();

        $truncated = $places->count() > self::PIN_CAP;
        $at = now();
        $pins = $places->take(self::PIN_CAP)->map(fn (Place $p) => $this->pin($p, $near, $at))->all();

        return ApiResponse::item(['pins' => $pins, 'clusters' => []], array_filter([
            'zoom' => $zoom,
            'total_in_bbox' => $total,
            'clustered' => false,
            'truncated' => $truncated ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     * @param  (Closure(PlaceQueryBuilder): mixed)|null  $constrain
     */
    private function clusteredResponse(MapPlacesRequest $request, array $bbox, ?Closure $constrain, int $zoom, int $total): JsonResponse
    {
        $cell = 360.0 / ((2 ** $zoom) * self::GRID_K);

        // Aggregate the (already filtered) places onto a grid. Reuse the base
        // builder's SQL + bindings so filters apply identically.
        $base = $this->baseQuery($request, $bbox, $constrain)->select('id')->addSelect(DB::raw('location::geometry AS geom'));
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
            // The SAME viewer point as the unclustered path. A pin that carries a
            // distance at zoom 15 and loses it at zoom 13 is a bug the user finds
            // by pinching, and one nothing but a test at both zooms would catch.
            $near = $request->nearPoint();
            $at = now();
            $pins = $this->selectPinFields(Place::query()->whereIn('id', $singletonIds), $near)
                ->get()
                ->map(fn (Place $p) => $this->pin($p, $near, $at))
                ->all();
        }

        return ApiResponse::item(['pins' => $pins, 'clusters' => $clusters], array_filter([
            'zoom' => $zoom,
            'total_in_bbox' => $total,
            'clustered' => true,
            'truncated' => $truncated ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Everything {@see pin()} reads, selected in one query.
     *
     * Shared by both paths — the raw-pin response and the singleton cells the
     * clustered response promotes to pins — because they render the SAME shape.
     * When they each spelled it out, a field added to `pin()` was one edit away
     * from being null on half the map.
     *
     * @param  array{lat: float, lng: float}|null  $near  the viewer's position,
     *                                                    or null when they gave none
     */
    private function selectPinFields(PlaceQueryBuilder $query, ?array $near = null): PlaceQueryBuilder
    {
        // Distance is computed by POSTGIS, once per row in the same query — not
        // in PHP per pin. At the pin cap that is the difference between one
        // query and 300 haversines, and it is what lets a caller sort on it
        // without a second pass.
        //
        // It is added AFTER `select('*')`, and that ordering is not cosmetic —
        // see {@see PlaceQueryBuilder::withDistanceFrom()}, which is also the one
        // place the alias and the `[lng, lat]` binding order are spelled.
        $query = $query
            ->select('*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng');

        if ($near !== null) {
            $query->withDistanceFrom($near);
        }

        return $query
            ->with([
                'primarySource.sourcePost.influencer',
                'primarySource.sourcePost.mediaAssets',
                'tags' => fn ($q) => $q->orderByDesc('place_tag.confidence')->orderBy('slug'),
            ])
            // The offer badge (T-042, 03 §3.3) — shared with the my-places list
            // so the two can never disagree about what "has an offer" means.
            ->withActiveOfferFlag();
    }

    /**
     * @param  array{lat: float, lng: float}|null  $near  the viewer's position
     * @param  \DateTimeInterface  $at  ONE clock reading for the whole response,
     *                                  required rather than defaulted so the
     *                                  invariant is the signature's rather than a
     *                                  comment's. A bare `now()` per pin lets a
     *                                  300-pin map served across a minute
     *                                  boundary report two venues with identical
     *                                  hours as one open and one closed.
     * @return array<string, mixed>
     */
    private function pin(Place $place, ?array $near, \DateTimeInterface $at): array
    {
        $sourcePost = $place->primarySource?->sourcePost;
        $influencer = $sourcePost?->influencer;

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
            'tags' => $place->tags->pluck('slug')->take(8)->values()->all(),
            'source_count' => $place->shares_count,
            'has_active_offer' => (bool) $place->getAttribute('has_active_offer'),
            // Marker photo: a curated place-owned picture (T-084) wins — the
            // marker thumbnail, else the main image — over the primary reel's
            // poster (T-070), which still draws the Google-style photo marker when
            // the place has no picture of its own. Null when neither exists.
            'thumbnail_url' => $place->thumbnail_url ?? $place->image_url ?? $this->resolveThumbnail($sourcePost),
            'top_influencer' => $influencer === null ? null : [
                'handle' => $influencer->handle,
                'display_name' => $influencer->display_name,
            ],
            // UNCONDITIONAL, unlike `distance_m`, and the asymmetry is the point:
            // open-or-closed is a fact about the VENUE — its periods, its own
            // timezone, the clock — and nothing about the viewer. Gating it on
            // `near` (as this first did) meant a user who declined location saw
            // no open/closed cue on any pin, while `/places`, `/search`, the feed
            // and every list showed one for the same restaurants. Two surfaces
            // disagreeing about a fact neither viewer influences.
            //
            // Null whenever the answer is not KNOWABLE — no structured periods,
            // or no timezone — and never a fabricated "closed" (T-155).
            'open_state' => $place->openState($at),
            // Appended, so the identity fields stay first and a reader sees the
            // viewer-relative field as the addition it is.
            //
            // ABSENT without a viewer position — not 0. Zero is a real distance
            // ("you are standing in it"), so a default is indistinguishable from
            // the truth. Same 1-decimal precision as {@see PlaceSummaryResource}:
            // one key name must not mean two shapes, and the client re-rounds by
            // magnitude, so an int here and a float there put the same restaurant
            // in two different kilometre buckets.
            ...($near === null ? [] : [
                'distance_m' => round((float) $place->getAttribute('distance'), 1),
            ]),
        ];
    }
}
