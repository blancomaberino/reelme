<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-PLACE soft-hide for the personal collection (T-071 fix). "Remove from my
 * map" was keyed on `feed_dismissals(user, share)`, but after the multi-place
 * work one share fans out to N places — so hiding one place hid every sibling
 * of the same post. This table keys the hide on (user, place) so removing one
 * pin leaves the others. Reversible — deleting the row un-hides it. The old
 * `feed_dismissals` stays for the (deprecated) feed; the map/collection now
 * reads this instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hidden_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'place_id']);
            $table->index('place_id');
        });

        // Carry existing collection-removes forward as per-place hides. CRITICAL:
        // `feed_dismissals` also stores feed-card hides of OTHER users' shares
        // (POST /feed/hidden) — which the old scopeMine ignored (it only consulted
        // dismissals nested inside `shares.user_id = me`, and never gated saves).
        // So restrict to a user's OWN published shares' published places, exactly
        // what the old collection-remove could hide; converting the rest would
        // wrongly strip places a user merely saved or feed-dismissed elsewhere.
        DB::statement(<<<'SQL'
            INSERT INTO hidden_places (user_id, place_id, created_at, updated_at)
            SELECT DISTINCT fd.user_id, ps.place_id, now(), now()
            FROM feed_dismissals fd
            JOIN shares s ON s.id = fd.share_id AND s.user_id = fd.user_id AND s.status = 'published'
            JOIN place_sources ps ON ps.share_id = fd.share_id AND ps.published_at IS NOT NULL
            JOIN places p ON p.id = ps.place_id AND p.merged_into_place_id IS NULL
            ON CONFLICT (user_id, place_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_places');
    }
};
