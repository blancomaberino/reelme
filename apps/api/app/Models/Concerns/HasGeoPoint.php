<?php

namespace App\Models\Concerns;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * PostGIS point I/O (T-106), extracted from `Place`.
 *
 * `location` is `geography(Point,4326)` and is never a plain Eloquent
 * attribute — a read returns WKB — so every model with a point needs this
 * write-via-expression / read-via-query pair. Isolating it also isolates the
 * classic PostGIS bug it guards against (see {@see setPoint()}).
 *
 * The using model supplies a `location` column and an `id`.
 */
trait HasGeoPoint
{
    /**
     * Stage the geography point for the next insert/update. `ST_MakePoint` takes
     * (lng, lat) — reversing them puts the pin in the ocean (the classic PostGIS
     * bug). Coordinates are floats from the geocoder, so inlining is injection-safe.
     */
    public function setPoint(float $lat, float $lng): void
    {
        if (! is_finite($lat) || ! is_finite($lng)) {
            throw new \InvalidArgumentException('Place coordinates must be finite.');
        }

        // number_format is locale-independent (unlike %f, which honors LC_NUMERIC
        // and would emit a comma decimal → a broken multi-arg ST_MakePoint call).
        $this->attributes['location'] = new Expression(sprintf(
            'ST_MakePoint(%s, %s)::geography',
            number_format($lng, 8, '.', ''),
            number_format($lat, 8, '.', ''),
        ));
    }

    /**
     * Read the stored point back as decimal degrees.
     *
     * @return array{lat: float, lng: float}
     */
    public function coordinates(): array
    {
        $row = DB::selectOne(
            'SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM places WHERE id = ?',
            [$this->id]
        );

        return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
    }
}
