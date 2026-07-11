<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Geocoder driver
    |--------------------------------------------------------------------------
    | `auto` uses Google Places when a key is configured, else the keyless
    | Nominatim (OpenStreetMap) driver — so the pipeline geocodes out of the box
    | for demo/dev. Force one with `google` or `nominatim`.
    */
    'driver' => env('GEOCODER_DRIVER', 'auto'),

    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        // Nominatim's usage policy requires an identifying User-Agent.
        'user_agent' => env('NOMINATIM_USER_AGENT', 'Reelmap/1.0 (self-hosted demo)'),
        'timeout' => (int) env('NOMINATIM_TIMEOUT', 10),
    ],

];
