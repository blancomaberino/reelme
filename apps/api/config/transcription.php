<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Primary driver
    |--------------------------------------------------------------------------
    | Local-first (ADR-005): whisper.cpp by default. The manager falls back to
    | the hosted driver when the primary is unavailable or errors.
    */
    'driver' => env('TRANSCRIBER_DRIVER', 'whisper_cpp'),

    'whisper_cpp' => [
        'bin' => env('WHISPER_BIN', 'whisper-cli'),
        'models_dir' => env('WHISPER_MODELS_DIR', storage_path('whisper')),
        'model' => env('WHISPER_MODEL', 'ggml-base'),
        'timeout' => (int) env('WHISPER_TIMEOUT', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted fallback (OpenAI-compatible /v1/audio/transcriptions)
    |--------------------------------------------------------------------------
    | Sends user audio to a third party — disabled by default (and in tests);
    | enable in prod. Any OpenAI-compatible endpoint works.
    */
    'hosted' => [
        'enabled' => (bool) env('TRANSCRIPTION_HOSTED_ENABLED', false),
        'base_url' => env('TRANSCRIPTION_HOSTED_URL', 'https://api.openai.com/v1'),
        'api_key' => env('TRANSCRIPTION_HOSTED_API_KEY'),
        'model' => env('TRANSCRIPTION_HOSTED_MODEL', 'whisper-1'),
        'timeout' => (int) env('TRANSCRIPTION_HOSTED_TIMEOUT', 300),
    ],
];
