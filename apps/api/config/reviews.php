<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Native review guardrails (T-059)
    |--------------------------------------------------------------------------
    | A deliberately small, curated blocklist — the real moderation surface is
    | the report → Filament hide queue; this only stops drive-by junk at the
    | door. Matching is case-insensitive on word boundaries.
    */

    'body_max_length' => 2000,

    // More links than this in one review body reads as spam.
    'max_links' => 2,

    'blocklist' => [
        'viagra', 'casino', 'forex', 'onlyfans', 'porn',
        'fuck', 'shit', 'cunt', 'nigger', 'faggot',
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-source review aggregator (T-082)
    |--------------------------------------------------------------------------
    | The pluggable ReviewSource drivers behind `review_sources[]` on the place
    | detail. Order here is the display order. `native` and `google` wrap signal
    | the place already carries; `trustpilot` fetches out of band (daily
    | `reelmap:trustpilot:refresh-stale`) into `external_place_reviews` and is
    | off until keyed. Each external driver caches within its OWN ToS window —
    | never one global TTL.
    */
    'sources' => [
        'native' => [
            'enabled' => (bool) env('REVIEWS_NATIVE_ENABLED', true),
        ],
        'google' => [
            'enabled' => (bool) env('REVIEWS_GOOGLE_ENABLED', true),
        ],
        'trustpilot' => [
            'enabled' => (bool) env('REVIEWS_TRUSTPILOT_ENABLED', false),
            'api_key' => env('TRUSTPILOT_API_KEY'),
            'base_url' => env('TRUSTPILOT_BASE_URL', 'https://api.trustpilot.com/v1'),
            'timeout' => (int) env('REVIEWS_TRUSTPILOT_TIMEOUT', 10),
            'refresh_after_days' => (int) env('REVIEWS_TRUSTPILOT_REFRESH_AFTER_DAYS', 7),
        ],
    ],
];
