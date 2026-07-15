<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media disks
    |--------------------------------------------------------------------------
    | Pipeline code (T-012 ManualUpload, T-017 Download/PrepareMedia) resolves
    | disks via THESE keys — never by literal disk name — so dev (local) and
    | prod (R2) differ only by config.
    |
    | Default is the local fallback so contributors need no R2 account. In
    | staging/prod set MEDIA_DISK=media and MEDIA_ORIGINALS_DISK=media_originals.
    */
    'disk' => env('MEDIA_DISK', 'local_media'),
    'originals_disk' => env('MEDIA_ORIGINALS_DISK', 'local_media_originals'),

    /*
    | Signed-URL lifetimes (minutes). Reads are short-lived; upload URLs shorter.
    */
    'get_url_ttl' => (int) env('MEDIA_GET_URL_TTL', 30),
    'put_url_ttl' => (int) env('MEDIA_PUT_URL_TTL', 15),

    /*
    | Max bytes accepted by the signed local-dev upload route (Content-Length cap).
    | Production uses native presigned R2 uploads, not this route.
    */
    'max_upload_bytes' => (int) env('MEDIA_MAX_UPLOAD_BYTES', 1024 * 1024 * 1024),

    // Path conventions are defined in App\Services\Media\MediaPaths (T-017 relies
    // on them) and documented in docs/media-retention.md. Originals vs derived
    // live on distinct disks/roots so the M5 deletion job + R2 lifecycle rule can
    // key off the `originals` prefix.

    /*
    |--------------------------------------------------------------------------
    | Media processing (T-017 — DownloadMedia / PrepareMedia)
    |--------------------------------------------------------------------------
    | Binary paths are configurable so CI/containers can point at their own
    | ffmpeg. Caps bound download cost; keyframe params drive the extraction.
    */
    'ffmpeg_bin' => env('MEDIA_FFMPEG_BIN', 'ffmpeg'),
    'ffprobe_bin' => env('MEDIA_FFPROBE_BIN', 'ffprobe'),

    // Hard caps enforced by DownloadMedia (→ failure code media_too_large).
    'max_download_bytes' => (int) env('MEDIA_MAX_DOWNLOAD_BYTES', 500 * 1024 * 1024),
    'max_duration_ms' => (int) env('MEDIA_MAX_DURATION_MS', 15 * 60 * 1000),

    // Per-image cap for post-image ingestion (T-013). `verify_image_host` runs a
    // DNS-based SSRF guard on resolved image URLs; disabled under the no-network
    // test env.
    'max_image_download_bytes' => (int) env('MEDIA_MAX_IMAGE_BYTES', 25 * 1024 * 1024),
    'verify_image_host' => (bool) env('MEDIA_VERIFY_IMAGE_HOST', true),

    // Sent when downloading resolved post images — a browser UA so image CDNs
    // (Instagram's especially) serve us instead of returning 403.
    'image_user_agent' => env('MEDIA_IMAGE_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'),

    // Keyframe extraction (PrepareMedia): scene-change threshold, hard frame cap,
    // the <min_scene_frames fallback to uniform sampling, and output sizing.
    'scene_threshold' => (float) env('MEDIA_SCENE_THRESHOLD', 0.3),
    'max_keyframes' => (int) env('MEDIA_MAX_KEYFRAMES', 12),
    'min_scene_frames' => (int) env('MEDIA_MIN_SCENE_FRAMES', 4),
    'keyframe_longest_edge' => (int) env('MEDIA_KEYFRAME_EDGE', 1024),
    'thumbnail_edge' => (int) env('MEDIA_THUMBNAIL_EDGE', 640),
    'audio_sample_rate' => (int) env('MEDIA_AUDIO_SAMPLE_RATE', 16000),
];
