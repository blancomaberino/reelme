<?php

namespace Tests\Load;

use App\Models\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Helpers for the map load test (T-053).
 *
 * A class rather than plain functions in the test file: a function declared in
 * a Pest test file is GLOBAL across the whole suite, and `mapUrl` /
 * `timeRequest` are exactly the names somebody else will reach for later. This
 * project has already paid for that lesson once (see tests/Helpers/).
 */
class MapProbe
{
    /**
     * `bbox` goes over the wire as one comma-joined string — see MapPlacesRequest.
     *
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     */
    public static function url(array $bbox, int $zoom): string
    {
        $joined = implode(',', [$bbox['minLng'], $bbox['minLat'], $bbox['maxLng'], $bbox['maxLat']]);

        return '/api/v1/map/places?'.http_build_query(['bbox' => $joined, 'zoom' => $zoom]);
    }

    /**
     * One request, with its wall-clock time in milliseconds.
     *
     * @return array{0: TestResponse, 1: float}
     */
    public static function time(TestCase $test, string $url): array
    {
        $start = hrtime(true);
        $response = $test->getJson($url);

        return [$response, (hrtime(true) - $start) / 1e6];
    }

    /**
     * The plan Postgres picks for the endpoint's real query over a given bbox.
     *
     * Built from the REAL `publiclyVisible()` scope and the REAL bbox predicate
     * rather than hand-written SQL. A copy would keep passing after either
     * changed underneath it, which is the one thing this must not do.
     *
     * @param  array{minLng: float, minLat: float, maxLng: float, maxLat: float}  $bbox
     */
    public static function plan(array $bbox): string
    {
        $query = Place::query()
            ->publiclyVisible()
            ->whereRaw(
                'location && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography',
                [$bbox['minLng'], $bbox['minLat'], $bbox['maxLng'], $bbox['maxLat']],
            )
            ->select('places.id');

        $plan = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

        return (string) json_encode($plan[0]->{'QUERY PLAN'} ?? $plan[0]);
    }
}
