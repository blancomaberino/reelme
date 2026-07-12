<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge audit log (T-035). Every admin merge snapshots everything needed to
 * reverse it — the merge itself deletes duplicate place_sources and backfills
 * the survivor's nulls, so without these snapshots a wrong merge loses
 * evidence permanently (M2 exit criterion: "a wrong merge can be undone").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_merges', function (Blueprint $table) {
            $table->id();
            // The tombstoned loser (B) and the survivor (A). Rows are kept
            // forever; places are never hard-deleted, so plain FKs are safe.
            $table->foreignId('source_place_id')->constrained('places');
            $table->foreignId('target_place_id')->constrained('places');
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // place_source ids moved B→A (restore = move back).
            $table->jsonb('rehomed_place_source_ids');
            // Full rows of B's sources deleted because A already held the share.
            $table->jsonb('dropped_duplicate_place_sources');
            // B's pre-merge attributes + tag pivots + per-source is_primary flags.
            $table->jsonb('source_snapshot');
            // A's pre-merge tag pivots (the union is lossy on collision).
            $table->jsonb('target_tag_pivots');
            // field => donated value copied onto A (nulled again on unmerge).
            $table->jsonb('target_backfilled_fields');
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            $table->index('source_place_id');
            $table->index('target_place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_merges');
    }
};
