<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place can now hold MULTIPLE business-owned photos, not just the single
 * `image_url` hero T-084 gave it (T-099).
 *
 * - `gallery_json` — an ordered list of `{ url, source, attribution }` where
 *   `source` ∈ website|google|reel and `attribution` is nullable. Business-owned
 *   website (schema.org) images rank first, then business-attributed Google
 *   photos, then the rest. `image_url`/`thumbnail_url` stay the hero/marker and
 *   are derived from `gallery_json[0]` when not human-locked, so a single-photo
 *   place and its map marker are unchanged. Lockable via T-084's `locked_fields`
 *   so a manual gallery/hero survives re-enrichment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->jsonb('gallery_json')->default('[]')->after('enriched_at');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropColumn('gallery_json');
        });
    }
};
