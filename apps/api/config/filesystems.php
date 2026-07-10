<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Media disks (Cloudflare R2 via the s3 driver). Everything is PRIVATE +
        | signed URLs (NFR-8) — R2 has no object ACLs, so never set visibility.
        | Resolve these via config/media.php + MediaUrlService, never by name.
        |
        | `media`           — derived, long-lived: keyframes, thumbnails, avatars.
        | `media_originals` — transient originals + screen recordings, ≤72h (ADR-010).
        | `local_media*`    — dev fallback (no R2 account needed); serve=true so
        |                     temporaryUrl() works via Laravel's signed local route.
        */
        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_REGION', 'auto'),
            'bucket' => env('MEDIA_BUCKET'),
            'endpoint' => env('MEDIA_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'root' => 'derived',
            'throw' => true,
            'report' => false,
        ],

        'media_originals' => [
            'driver' => 's3',
            'key' => env('MEDIA_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_REGION', 'auto'),
            'bucket' => env('MEDIA_BUCKET'),
            'endpoint' => env('MEDIA_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'root' => 'originals',
            'throw' => true,
            'report' => false,
        ],

        'local_media' => [
            'driver' => 'local',
            'root' => storage_path('app/media/derived'),
            // Distinct served URL so it doesn't collide with the `local` disk's /storage route.
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/media-derived',
            'serve' => true,
            'throw' => true,
            'report' => false,
        ],

        'local_media_originals' => [
            'driver' => 'local',
            'root' => storage_path('app/media/originals'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/media-originals',
            'serve' => true,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
