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
    |--------------------------------------------------------------------------
    | Path conventions (see App\Services\Media\MediaPaths — T-017 relies on these)
    |--------------------------------------------------------------------------
    | originals disk : media/{share_id}/original/{sha256}.{ext}
    | media disk     : media/{share_id}/frames/frame_{index}_{ms}.jpg
    |                  media/{share_id}/thumb.jpg
    |                  media/{share_id}/audio.wav
    |
    | Originals and derived live under distinct DISKS/roots so the M5 deletion
    | job (T-050) and the R2 lifecycle rule can key off the `originals` prefix.
    */
];
