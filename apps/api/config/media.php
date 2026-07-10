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
];
