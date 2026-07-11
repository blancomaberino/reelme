<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the shares ⇄ place_sources circular dependency (02 §6): the nullable FK
 * is added after place_sources exists. `review_meta_json` carries the candidate
 * list for an ambiguous resolution so the review/picker UI (T-026) can render it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->foreignId('published_place_source_id')->nullable()->after('analysis_run_id')
                ->constrained('place_sources')->nullOnDelete();
            $table->jsonb('review_meta_json')->nullable()->after('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_place_source_id');
            $table->dropColumn('review_meta_json');
        });
    }
};
