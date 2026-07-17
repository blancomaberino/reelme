<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dedup decision tree (04 §6)
    |--------------------------------------------------------------------------
    | Tunable without code changes. Distances are meters (geography space), the
    | similarity threshold is 0–1 (max of pg_trgm similarity + Jaro-Winkler).
    */
    'dedup' => [
        'radius_meters' => (float) env('PLACES_DEDUP_RADIUS_M', 75),
        'name_similarity_threshold' => (float) env('PLACES_DEDUP_SIMILARITY', 0.85),
    ],

    /*
    | A geocode result scoring below this is treated as "not found" → the share
    | is parked for human review rather than pinned at a bad location.
    */
    'geocode' => [
        'min_score' => (float) env('PLACES_GEOCODE_MIN_SCORE', 0.5),
    ],

    /*
    | Seconds a resolve holds the per-canonical lock, serializing concurrent
    | shares of the same place so they can't create duplicate pins.
    */
    'lock_seconds' => (int) env('PLACES_RESOLVE_LOCK_SECONDS', 30),

    /*
    | Instagram-profile fallback (T-075): when the geocoder misses but the place
    | carries an @handle, fetch that venue's IG profile and re-resolve from its
    | business address / bio locality / full_name. Uses the same IG session cookie
    | as the carousel-image resolver (INGESTION_IG_* / ingestion.instagram_api).
    | Disable to keep the honest geocode_failed → review behaviour.
    */
    'ig_profile' => [
        'enabled' => (bool) env('PLACES_IG_PROFILE_ENABLED', true),
    ],

    /*
    | Card-discount issuer resolution (T-079): when a caption attributes a payment
    | discount to a bank/card only by an @mention (no plain-text name), fetch that
    | issuer's Instagram profile and use its full_name as the display label. Uses
    | the same IG session cookie as the venue-profile locator (INGESTION_IG_*).
    | Disable to keep the raw @handle label.
    */
    'card_discounts' => [
        'resolve_issuer' => (bool) env('PLACES_CARD_ISSUER_RESOLVE', true),
    ],

];
