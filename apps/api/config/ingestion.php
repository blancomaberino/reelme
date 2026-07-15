<?php

use App\Adapters\ManualUploadAdapter;
use App\Adapters\OEmbedAdapter;
use App\Services\Media\Images\OEmbedThumbnailResolver;
use App\Services\Media\Images\YtDlpResolver;

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
    | wins; each URL is downloaded and stored as a keyframe the model sees.
    | yt-dlp (full carousel + video cover frames) is tried first; it needs the
    | binary in the container (dev.sh installs it) and, for private/rate-limited
    | posts, a cookie file. When it can't produce images it falls through to the
    | zero-auth oEmbed thumbnail (the hero image). Prepend a paid IG media API
    | here later — a one-line change, no pipeline rewrite.
    */
    'image_resolvers' => [
        YtDlpResolver::class,
        OEmbedThumbnailResolver::class,
    ],

    /*
    | yt-dlp resolver knobs. `cookies_path` is a Netscape cookies.txt (exported
    | from a logged-in browser) so authed/private posts resolve; leave null for
    | public-only. Set the path INSIDE the container (the app dir mounts at
    | /var/www/html). Disable the resolver entirely with INGESTION_YTDLP_ENABLED=false.
    */
    'yt_dlp' => [
        'enabled' => (bool) env('INGESTION_YTDLP_ENABLED', true),
        'bin' => env('INGESTION_YTDLP_BIN', 'yt-dlp'),
        'timeout' => (int) env('INGESTION_YTDLP_TIMEOUT', 45),
        'cookies_path' => env('INGESTION_IG_COOKIES_PATH'),
    ],

    'oembed' => [
        'timeout' => (int) env('OEMBED_TIMEOUT', 10),
        'user_agent' => env('OEMBED_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
    ],
];
