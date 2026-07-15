<?php

use App\Adapters\ManualUploadAdapter;
use App\Adapters\OEmbedAdapter;
use App\Services\Media\Images\OEmbedThumbnailResolver;

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
        // Keyless public oEmbed → real link title/author for the text path.
        // Instagram's endpoint is keyless but best-effort (undocumented, IP-limited).
        'instagram' => [OEmbedAdapter::class],
        'x' => [],
        'tiktok' => [OEmbedAdapter::class],
        'youtube' => [OEmbedAdapter::class],
    ],

    'fallback' => ManualUploadAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | Post image resolvers (T-013)
    |--------------------------------------------------------------------------
    | Priority-ordered PostImageResolver classes. When a post has no video,
    | PrepareMedia runs this chain and the FIRST resolver that returns image URLs
    | wins; each URL is downloaded and stored as a keyframe the model sees. Today
    | only the zero-auth oEmbed thumbnail (hero image). Prepend an authenticated
    | resolver (yt-dlp for full carousels, or a paid IG media API) to upgrade —
    | a one-line change, no pipeline rewrite.
    */
    'image_resolvers' => [
        OEmbedThumbnailResolver::class,
    ],

    'oembed' => [
        'timeout' => (int) env('OEMBED_TIMEOUT', 10),
        'user_agent' => env('OEMBED_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
    ],
];
