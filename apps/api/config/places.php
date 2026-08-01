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

    /*
    | Google-ToS refresh window (T-059/T-080). Cached Google review content may
    | not be kept beyond ~30 days; past this age it must be refreshed or dropped.
    | Drives both the daily `reelmap:google:refresh-stale` sweep AND the on-demand
    | refresh a re-share triggers when it re-resolves a known-but-stale place.
    */
    'google' => [
        'refresh_after_days' => (int) env('PLACES_GOOGLE_REFRESH_AFTER_DAYS', 30),
    ],

    /*
    | Enrich a place's curated fields + photo gallery from external sources
    | (T-084/T-099). Runs automatically on a place's FIRST publish (`auto` below)
    | and on demand via the Filament "Enrich as business" action. Each source is
    | individually gated and failure-isolated; a locked (human-set) field is never
    | overwritten. The Google source uses a WIDER Places field mask than the
    | pipeline (extra billed SKU), so `auto` bills that SKU once per new place —
    | disable it (or the Google source) to keep enrichment admin-triggered only.
    */
    'enrich' => [
        // Auto-enrich a place the FIRST time it is published (T-099 follow-up):
        // the publish stage queues an EnrichPlace job so a shared place shows its
        // business data + photo gallery without waiting for a manual admin action.
        // Guarded on `enriched_at` so a re-share never re-bills external sources;
        // the Filament "Enrich as business" action can always re-run on demand.
        // Off in the test env (external calls); admins can disable per env.
        'auto' => (bool) env('PLACES_ENRICH_AUTO', true),
        'google' => [
            'enabled' => (bool) env('PLACES_ENRICH_GOOGLE_ENABLED', true),
            // Max width (px) requested for a Google Places Photo (T-099). The photo
            // is part of the same Details call (added to the wider BUSINESS_FIELDS
            // mask, NOT the pipeline's billing-sensitive DETAILS_FIELDS). NOTE: the
            // repo uses the LEGACY Places API — `photos[].html_attributions` names
            // the UPLOADER, not "the owner", so business ownership is a name/domain
            // heuristic (ADR: the new Places API v1 `authorAttributions` would let
            // us identify owner photos more reliably).
            'photo_maxwidth' => (int) env('PLACES_ENRICH_GOOGLE_PHOTO_MAXWIDTH', 1024),
            // How many Google photos to RESOLVE per enrich. Each resolution is a
            // separate (billed) Places Photo request, and website-owned images
            // usually outrank Google in the gallery, so keep this modest — a few
            // fill photos, not the whole set.
            'max_photos' => (int) env('PLACES_ENRICH_GOOGLE_MAX_PHOTOS', 6),
        ],
        // Multi-photo gallery (T-099): collect business-owned images across the
        // sources into an ordered `gallery_json`. Website (schema.org) images are
        // definitively owned → ranked first; Google photos are best-effort,
        // business-attributed first then filled. Disabled ⇒ the single-image T-084
        // behaviour (hero/marker from a lone `image_url`).
        'gallery' => [
            'enabled' => (bool) env('PLACES_ENRICH_GALLERY_ENABLED', true),
            'max_images' => (int) env('PLACES_ENRICH_GALLERY_MAX_IMAGES', 8),
        ],
        'website' => [
            'enabled' => (bool) env('PLACES_ENRICH_WEBSITE_ENABLED', true),
            // Cap + timeout for the business-site fetch (SSRF-guarded, no redirects).
            'timeout_seconds' => (int) env('PLACES_ENRICH_WEBSITE_TIMEOUT', 8),
            'max_bytes' => (int) env('PLACES_ENRICH_WEBSITE_MAX_BYTES', 512 * 1024),
            // Cache a scraped site this many days (per website URL).
            'cache_days' => (int) env('PLACES_ENRICH_WEBSITE_CACHE_DAYS', 7),
            // DNS-resolve + vet the host is public. Disabled in the no-network
            // test env (mirrors media.verify_image_host); production keeps it on.
            'verify_host' => (bool) env('PLACES_ENRICH_WEBSITE_VERIFY_HOST', true),
        ],
        'reviews' => [
            'enabled' => (bool) env('PLACES_ENRICH_REVIEWS_ENABLED', true),
        ],
    ],

    // Restaurant-owner claiming (T-041, 06 §2.1).
    'claims' => [
        // Timeout for fetching /.well-known/reelmap-verify.txt on the claimant's
        // own domain.
        'website_timeout_seconds' => (int) env('PLACES_CLAIM_WEBSITE_TIMEOUT', 8),
        // DNS-resolve + vet that the host is public before fetching it. The URL
        // comes from `places.website`, which was extracted from a third party,
        // so this is what stops "verify my website" being a request-forgery
        // primitive. Mirrors enrich.website.verify_host: off in the no-network
        // test env, ON everywhere else.
        'verify_host' => (bool) env('PLACES_CLAIM_VERIFY_HOST', true),
    ],

];
