<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Feature tests boot the full application (Tests\TestCase) and run against a
| migrated Postgres database (RefreshDatabase) so citext/PostGIS behave as in
| production. Unit tests stay framework-free for speed.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

require_once __DIR__.'/Helpers/PipelineHelpers.php';
