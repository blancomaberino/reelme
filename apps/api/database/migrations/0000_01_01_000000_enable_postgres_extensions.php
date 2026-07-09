<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Must be the FIRST migration to run (02-data-model.md §3.8/§6). Every table
 * that follows relies on these extensions: PostGIS for geography, pg_trgm +
 * unaccent for fuzzy place matching, citext for case-insensitive handles/emails.
 * Requires a superuser role — the Sail postgis/postgis image grants it; CI uses
 * the same image (never plain postgres).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS citext');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
