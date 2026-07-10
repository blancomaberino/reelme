<?php

use App\Adapters\ManualUploadAdapter;

return [
    /*
    |--------------------------------------------------------------------------
    | Adapter chains
    |--------------------------------------------------------------------------
    | Priority-ordered SourceAdapter classes per platform. FetchSourcePost (T-016)
    | walks the chain: first supports()==true wins; on FetchFailed/PostUnavailable
    | it advances. AdapterRegistry ALWAYS appends `fallback` last, so every chain
    | terminates in manual upload (ADR-011). Real platform adapters land in
    | T-013 (Instagram), T-014 (X/TikTok/YouTube), T-015 (authed Instagram).
    */
    'chains' => [
        'instagram' => [],
        'x' => [],
        'tiktok' => [],
        'youtube' => [],
    ],

    'fallback' => ManualUploadAdapter::class,
];
