<?php

use App\Adapters\ManualUploadAdapter;
use App\Adapters\OEmbedAdapter;
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
    | fallback. Instagram uses the generic keyless OEmbedAdapter; X/TikTok/YouTube
    | have dedicated adapters (T-014) that parse each provider's oEmbed/API shape
    | (X blockquote HTML, TikTok author_unique_id, YouTube Data API v3 or oEmbed).
    */
    'chains' => [
        // Instagram's oEmbed is keyless but best-effort (undocumented, IP-limited).
        'instagram' => [OEmbedAdapter::class, YtDlpAdapter::class],
        'x' => [XAdapter::class, YtDlpAdapter::class],
        'tiktok' => [TikTokAdapter::class, YtDlpAdapter::class],
        'youtube' => [YouTubeAdapter::class, YtDlpAdapter::class],
    ],

    'fallback' => ManualUploadAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | Per-platform kill switches (T-014, 01 §5 operational rules)
    |--------------------------------------------------------------------------
    | Force any platform's metadata adapter to stand down without a deploy: its
    | supports() returns false, so a share degrades to the yt-dlp/manual tail of
    | the chain. Use when a provider's endpoint breaks or a ToS concern arises.
    | (yt-dlp has its own `ytdlp.enabled` switch below.)
    */
    'platforms' => [
        'x' => ['enabled' => (bool) env('INGESTION_X_ENABLED', true)],
        'tiktok' => ['enabled' => (bool) env('INGESTION_TIKTOK_ENABLED', true)],
        'youtube' => ['enabled' => (bool) env('INGESTION_YOUTUBE_ENABLED', true)],
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
