<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shared contract locations
    |--------------------------------------------------------------------------
    | The canonical JSON Schemas live in the monorepo's packages/contracts.
    | Paths are resolved relative to the repo root by default (the whole
    | monorepo is checked out locally and in CI) but are overridable via env so
    | a deploy that ships only apps/api can point at a vendored copy.
    */
    'extraction_schema_path' => env(
        'CONTRACTS_EXTRACTION_SCHEMA_PATH',
        base_path('../../packages/contracts/extraction.schema.json'),
    ),

    'examples_path' => env(
        'CONTRACTS_EXAMPLES_PATH',
        base_path('../../packages/contracts/examples'),
    ),
];
