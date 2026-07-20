<?php

use App\Adapters\InstagramAdapter;
use App\Adapters\ManualUploadAdapter;
use App\Adapters\TikTokAdapter;
use App\Adapters\XAdapter;
use App\Adapters\YouTubeAdapter;
use App\Adapters\YtDlpAdapter;
use App\Services\Media\Images\InstagramApiResolver;
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
    |
    | A keyless metadata adapter leads each chain so it supplies the caption/author
    | (first successful fetchMetadata wins); YtDlpAdapter (T-074) follows to
    | download the real video (metadata adapters expose none, so DownloadMedia
    | advances to yt-dlp), giving the pipeline actual scene keyframes + audio.
    | yt-dlp missing/auth-walled → the caption-only path remains the graceful
    | fallback. Each platform has a dedicated metadata adapter that parses its
    | own oEmbed/API shape: Instagram (keyless oEmbed), X (blockquote HTML),
    | TikTok (author_unique_id), YouTube (Data API v3 or oEmbed) — all T-013/T-014.
    */
    'chains' => [
        // Instagram's oEmbed is keyless but best-effort (undocumented, IP-limited).
        'instagram' => [InstagramAdapter::class, YtDlpAdapter::class],
        'x' => [XAdapter::class, YtDlpAdapter::class],
        'tiktok' => [TikTokAdapter::class, YtDlpAdapter::class],
        'youtube' => [YouTubeAdapter::class, YtDlpAdapter::class],
    ],

    'fallback' => ManualUploadAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | Per-platform enablement (T-014, 01 §5 operational rules)
    |--------------------------------------------------------------------------
    | The single source of truth for which sources the app accepts. Both layers
    | read it: ShareController REJECTS a share from a disabled platform ("only
    | Instagram is supported right now"), and AdapterRegistry skips a disabled
    | platform's ENTIRE chain (metadata adapter AND yt-dlp) → manual fallback.
    |
    | LAUNCH POSTURE: Instagram-only. X / TikTok / YouTube ship DISABLED. Enabling
    | a source end-to-end is a one-line env flip (no deploy) — e.g. set
    | `INGESTION_TIKTOK_ENABLED=true`. An unlisted platform (instagram) is always
    | enabled.
    */
    'platforms' => [
        'x' => ['enabled' => (bool) env('INGESTION_X_ENABLED', false)],
        'tiktok' => ['enabled' => (bool) env('INGESTION_TIKTOK_ENABLED', false)],
        'youtube' => ['enabled' => (bool) env('INGESTION_YOUTUBE_ENABLED', false)],
    ],

    /*
    |--------------------------------------------------------------------------
    | Post image resolvers (T-013)
    |--------------------------------------------------------------------------
    | Priority-ordered PostImageResolver classes. When a post has no video,
    | PrepareMedia runs this chain and the FIRST resolver that returns image URLs
    | wins; each URL is downloaded and stored as a keyframe the model sees. The
    | authenticated InstagramApiResolver returns EVERY carousel slide (the menu
    | shots), falling through to the zero-auth oEmbed thumbnail (hero image only)
    | when no session cookie is configured or the call fails.
    */
    'image_resolvers' => [
        InstagramApiResolver::class,
        OEmbedThumbnailResolver::class,
    ],

    /*
    | InstagramApiResolver knobs. `cookies_path` is a Netscape cookies.txt
    | exported from a logged-in browser (needs at least `sessionid`); without it
    | the resolver is a no-op and the chain uses the oEmbed hero image. yt-dlp is
    | intentionally NOT used for images — its IG extractor only handles video.
    */
    'instagram_api' => [
        'enabled' => (bool) env('INGESTION_IG_API_ENABLED', true),
        'cookies_path' => env('INGESTION_IG_COOKIES_PATH'),
        'timeout' => (int) env('INGESTION_IG_API_TIMEOUT', 15),
    ],

    'oembed' => [
        'timeout' => (int) env('OEMBED_TIMEOUT', 10),
        'user_agent' => env('OEMBED_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),
    ],

    /*
    | YtDlpAdapter knobs (T-074). Downloads a post's real video so the pipeline
    | gets scene keyframes + audio instead of caption-only. `bin` is the yt-dlp
    | binary (dev.sh drops it into the container; prod bakes it into the worker
    | image/host). `cookies_path` reuses the same Netscape cookies.txt as the IG
    | image resolver — needed only for private/rate-limited posts. Disable to
    | force the caption-only path.
    */
    'ytdlp' => [
        'enabled' => (bool) env('INGESTION_YTDLP_ENABLED', true),
        'bin' => env('INGESTION_YTDLP_BIN', 'yt-dlp'),
        'timeout' => (int) env('INGESTION_YTDLP_TIMEOUT', 120),
        'cookies_path' => env('INGESTION_YTDLP_COOKIES_PATH', env('INGESTION_IG_COOKIES_PATH')),
    ],
];
