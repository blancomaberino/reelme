<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\Tag;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Scout (T-031)
    |--------------------------------------------------------------------------
    | Meilisearch in dev/prod (self-hosted, 01-architecture §1); `collection`
    | is the default so tests and one-off CLI runs need no search server.
    | Sync is synchronous (queue=false) — the write volume is one place/tag
    | per publish, and the demo relies on immediately-searchable pins.
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    // Per-env prefix so dev/testing/CI runs never clobber each other's indexes.
    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),

        // Pushed by `reelmap:search:reindex` (scout:sync-index-settings) —
        // settings must land BEFORE documents rely on _geo/filterables.
        'index-settings' => [
            Place::class => [
                'searchableAttributes' => ['name', 'normalized_name', 'tags', 'city', 'cuisine_primary'],
                'filterableAttributes' => ['price_range', 'cuisine_primary', 'tags', 'country_code', '_geo'],
                'sortableAttributes' => ['shares_count', '_geo'],
            ],
            Tag::class => [
                'searchableAttributes' => ['name', 'slug'],
                'filterableAttributes' => ['kind'],
            ],
            Influencer::class => [
                'searchableAttributes' => ['handle', 'display_name'],
                'filterableAttributes' => ['platform'],
            ],
            User::class => [
                'searchableAttributes' => ['username', 'name', 'bio'],
            ],
        ],
    ],

];
