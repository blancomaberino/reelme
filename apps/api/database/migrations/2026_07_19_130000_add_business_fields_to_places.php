<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places become first-class, curatable businesses (T-084). A place can now carry
 * its own picture (independent of the reel it was first seen in), remember which
 * fields a human hand-set so on-demand enrichment / a re-share resolve never
 * clobbers them, and record when it was last enriched.
 *
 * - `image_url`      — the main business photo (place detail hero).
 * - `thumbnail_url`  — the map-marker photo; falls back to `image_url` when null.
 *   (Both hold a URL — an admin upload lands on the media disk, enrichment
 *   discovers one; distinct from the reel-derived thumbnail served today.)
 * - `locked_fields`  — jsonb list of column names a human owns; the enricher and
 *   the resolve backfills skip these so a manual override always wins.
 * - `enriched_at`    — last successful "enrich as business" run (display only;
 *   per-source ToS windows live in their own caches).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->string('image_url', 2048)->nullable()->after('website');
            $table->string('thumbnail_url', 2048)->nullable()->after('image_url');
            $table->jsonb('locked_fields')->default('[]')->after('thumbnail_url');
            $table->timestamp('enriched_at')->nullable()->after('locked_fields');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropColumn(['image_url', 'thumbnail_url', 'locked_fields', 'enriched_at']);
        });
    }
};
