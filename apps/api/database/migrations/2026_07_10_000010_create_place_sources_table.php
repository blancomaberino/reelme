<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance + attribution backbone (02 §3.9): N posts (via N shares) map to one
 * deduped place. `extraction_snapshot_json` freezes the extracted-place payload
 * at publish. Uniqueness enforces idempotency (one source per place per share)
 * and the invariants: a share publishes at most one place, one primary per place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->foreignId('source_post_id')->constrained('source_posts')->cascadeOnDelete();
            $table->foreignId('share_id')->constrained('shares')->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->jsonb('extraction_snapshot_json');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['place_id', 'share_id']);
            $table->unique('share_id');
            $table->index('source_post_id');
        });

        // Exactly one primary source per place.
        DB::statement('CREATE UNIQUE INDEX place_sources_one_primary_per_place ON place_sources (place_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('place_sources');
    }
};
