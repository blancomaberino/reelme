<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-021 extraction wiring: record which system-prompt version produced each run,
 * and let a share point at its winning run + carry a machine-readable review
 * reason. The share→run pointer is a nullable circular FK (analysis_runs already
 * references shares); Postgres allows this because the column is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->string('prompt_version', 32)->nullable()->after('model');
        });

        Schema::table('shares', function (Blueprint $table) {
            $table->foreignId('analysis_run_id')->nullable()->after('source_post_id')
                ->constrained('analysis_runs')->nullOnDelete();
            $table->string('review_reason', 64)->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('analysis_run_id');
            $table->dropColumn('review_reason');
        });

        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropColumn('prompt_version');
        });
    }
};
