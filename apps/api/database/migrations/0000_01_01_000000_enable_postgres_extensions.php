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
        // Intentionally a no-op: extensions are database-level, shared
        // infrastructure. The postgis/postgis image also auto-installs
        // postgis_topology/postgis_tiger_geocoder which depend on postgis, so a
        // DROP would fail on rollback — and dropping extensions out from under
        // other migrations/objects is never what a rollback wants.
    }
};
