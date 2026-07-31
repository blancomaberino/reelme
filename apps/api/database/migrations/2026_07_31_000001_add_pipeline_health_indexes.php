<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the pipeline-health dashboard (T-107).
 *
 * Both tables were indexed for their row-level access patterns — fetching one
 * share's stages, filtering shares by status — but the dashboard asks the
 * opposite question: aggregate everything inside a time window. Without these,
 * every widget is a sequential scan that gets slower with each share ever
 * ingested, which is precisely when an operator needs the page to load.
 *
 * `status` leads the shares index because the widget always pairs the two, and
 * a leading equality column keeps the range on `created_at` a single scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_stage_metrics', function (Blueprint $table) {
            // Durations: WHERE started_at >= ? GROUP BY stage.
            $table->index(['stage', 'started_at'], 'ssm_stage_started_at_index');
            // The "oldest wedged stage" probe: WHERE status = 'running' MIN(started_at).
            $table->index(['status', 'started_at'], 'ssm_status_started_at_index');
        });

        Schema::table('shares', function (Blueprint $table) {
            // Status counts and the failure mix, both windowed on created_at.
            $table->index(['status', 'created_at'], 'shares_status_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('share_stage_metrics', function (Blueprint $table) {
            $table->dropIndex('ssm_stage_started_at_index');
            $table->dropIndex('ssm_status_started_at_index');
        });

        Schema::table('shares', function (Blueprint $table) {
            $table->dropIndex('shares_status_created_at_index');
        });
    }
};
