<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP rate limits (03 §1)
    |--------------------------------------------------------------------------
    | Per MINUTE unless the name says otherwise. Every one of these is
    | env-backed because NFR/FR-58 asks for them to be tunable without a
    | deploy — a limiter you cannot raise during an incident is a limiter that
    | gets removed during an incident.
    |
    | Authenticated limits key on the USER, never the IP: mobile carriers NAT
    | thousands of subscribers behind one address, so an IP-keyed authenticated
    | limit throttles a city because one person was busy.
    */
    'rate' => [
        // The catch-all for authenticated API traffic.
        'default' => (int) env('RATE_DEFAULT_PER_MINUTE', 60),

        // Anonymous reads (public place pages, the map without a session).
        'public' => (int) env('RATE_PUBLIC_PER_MINUTE', 30),

        // Auth endpoints are IP-keyed on purpose — there is no user yet, and
        // the thing being bounded is guessing.
        'auth' => (int) env('RATE_AUTH_PER_MINUTE', 5),

        // The map is polled by panning, so it needs headroom the default
        // cannot give.
        'map' => (int) env('RATE_MAP_PER_MINUTE', 120),

        /*
         * Share-status polling. AnalysisStatus polls every 2.5s = 24/min, and
         * that is ONE screen: sharing two links and watching both would eat
         * most of a 60/min default before the app made any other request. A
         * separate, higher bucket keeps a normal session from being throttled
         * for behaving exactly as designed.
         */
        'polling' => (int) env('RATE_POLLING_PER_MINUTE', 90),

        // A till scanning codes is bursty; the real anti-fraud bound is the
        // hourly cap in RedemptionGuards, not this.
        'verify' => (int) env('RATE_VERIFY_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily quotas (NFR-12)
    |--------------------------------------------------------------------------
    | These are the "N per day" limits, distinct from the burst limiters above.
    | They reset at midnight **UTC** — one boundary everywhere, matching the
    | auto-retry for a share parked over the AI budget (04 §3) and stated as
    | such in the mobile copy. A local-midnight reset would mean the answer to
    | "when does this come back" depends on where the user is standing.
    */
    'daily' => [
        'shares' => (int) env('SHARES_DAILY_LIMIT', 100),
        'reviews' => (int) env('REVIEWS_DAILY_LIMIT', 100),
    ],
];
