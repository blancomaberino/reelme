<?php

namespace App\Services\Redemptions;

use App\Exceptions\RedemptionInvalid;
use App\Models\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Was this actually verified at the restaurant?" (T-043, 06 §3).
 *
 * The control the geofence really provides is that a code cannot be redeemed by
 * a staff member sitting at home with a list of codes a diner sent them. It is
 * NOT proof of presence — a phone's reported location is client-supplied and
 * spoofable, which 06 §3 records as an accepted v1 residual risk. So the outcome
 * is always WRITTEN to the row (`geofence_ok`, `geofence_distance_m`) whether it
 * passes or fails: the durable value here is the audit trail an admin can review
 * when a venue's numbers look wrong, not the block itself.
 *
 * A MISSING location is not a failure. Staff deny location permission, indoor
 * GPS is unreliable, and a restaurant that cannot serve its customers because
 * the phone could not get a fix is a worse outcome than an unverified location.
 * Absent readings are recorded as unknown (`geofence_ok = null`) and let through.
 */
class RedemptionGeofence
{
    /** 06 §3: the staff device must report within 500 m of the venue. */
    public const RADIUS_M = 500;

    /**
     * @return array{ok: bool|null, distance_m: int|null}
     *
     * @throws RedemptionInvalid when a location IS supplied and is out of range
     */
    public function check(Place $place, ?float $lat, ?float $lng): array
    {
        if ($lat === null || $lng === null) {
            Log::info('redemption.geofence_unknown', ['place_id' => $place->id]);

            return ['ok' => null, 'distance_m' => null];
        }

        $distance = $this->distanceMeters($place, $lat, $lng);

        if ($distance === null) {
            // The venue has no stored point — nothing to measure against. Same
            // treatment as a missing device reading: record, do not block.
            Log::warning('redemption.geofence_no_place_location', ['place_id' => $place->id]);

            return ['ok' => null, 'distance_m' => null];
        }

        if ($distance > self::RADIUS_M) {
            Log::warning('redemption.geofence_failed', [
                'place_id' => $place->id,
                'distance_m' => $distance,
                'radius_m' => self::RADIUS_M,
            ]);

            throw RedemptionInvalid::outsideGeofence($distance);
        }

        return ['ok' => true, 'distance_m' => $distance];
    }

    /**
     * Metres between the venue and the reported point, or null when the venue
     * has no location. PostGIS `ST_Distance` on `geography` returns metres
     * directly — no projection, no haversine by hand.
     */
    private function distanceMeters(Place $place, float $lat, float $lng): ?int
    {
        $row = DB::table('places')
            ->where('id', $place->id)
            ->whereNotNull('location')
            ->selectRaw(
                'ST_Distance(location, ST_MakePoint(?, ?)::geography) AS distance_m',
                [$lng, $lat],
            )
            ->first();

        return $row === null ? null : (int) round((float) $row->distance_m);
    }
}
