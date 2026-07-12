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
];
