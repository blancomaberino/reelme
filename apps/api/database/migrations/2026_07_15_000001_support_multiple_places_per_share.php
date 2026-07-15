<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-place shares (T-013 follow-up): a single post can review several venues
 * (a roundup, a "where I ate today" tour). Lifts the "one place per share"
 * invariant so one share fans out to N place_sources.
 *
 * - Drops place_sources.unique(share_id) — the hard 1:1 lock — while KEEPING
 *   unique(place_id, share_id) (idempotency: one source per place per share).
 * - Adds place_sources.published_at: a source is live in the feed once set, so
 *   the feed/profile can fan out to one card per published source and a share
 *   can PARTIALLY publish (some places live, others parked for review).
 * - shares.published_place_source_id stays as the "primary" published source
 *   (first/primary place) for back-compat and the map "jump to pin" affordance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_sources', function (Blueprint $table) {
            $table->dropUnique(['share_id']); // place_sources_share_id_unique — the 1:1 lock
            $table->timestampTz('published_at')->nullable()->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('place_sources', function (Blueprint $table) {
            $table->dropColumn('published_at');
            $table->unique('share_id');
        });
    }
};
