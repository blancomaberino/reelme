<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `place_sources.published_at` for shares that published BEFORE the
 * multi-place migration added the column (T-071 fix). Such a source has
 * `published_at = NULL` even though its share is `published`, so it was dropped
 * from `Share::publishedPlaceSources()` (`whereNotNull(published_at)`) — hiding
 * the pin from the share-detail `places[]` and under-counting `shares_count`.
 * Give each already-published source its share's publish time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE place_sources ps
            SET published_at = COALESCE(s.published_at, s.updated_at, ps.created_at)
            FROM shares s
            WHERE s.id = ps.share_id
              AND s.status = 'published'
              AND ps.published_at IS NULL
        SQL);
    }

    public function down(): void
    {
        // No down-migration: the backfilled timestamps are indistinguishable from
        // legitimately-published sources and safe to keep.
    }
};
