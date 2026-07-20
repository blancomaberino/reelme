<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    // YouTube Data API v3 (T-014). When set, YouTubeAdapter uses it for full
    // video descriptions; unset, it silently falls back to the keyless oEmbed.
    'youtube' => [
        'api_key' => env('YOUTUBE_API_KEY'),
    ],

    // Instagram OAuth linking (T-015). Powers the Socialite "instagram" driver
    // (Instagram API with Instagram Login — a Professional/Business account, the
    // deprecated Basic Display flow is gone). `redirect` must point at the
    // platform-accounts callback route. Scopes are configurable — granted scopes
    // are stored per-account. Client id/secret unset ⇒ linking is simply
    // unavailable (the pipeline still runs on keyless oEmbed).
    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INSTAGRAM_SCOPES', 'instagram_business_basic'))
        ))),
        // Graph base + request timeout for the authed media fetch (InstagramGraphAdapter).
        'graph_base' => env('INSTAGRAM_GRAPH_BASE', 'https://graph.instagram.com'),
        'timeout' => (int) env('INSTAGRAM_GRAPH_TIMEOUT', 10),
    ],

];
