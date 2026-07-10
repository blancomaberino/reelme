<?php

use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One AI extraction attempt per row (02 §3.7). A share may have several runs
 * (local failed → openrouter retry; user re-runs with a different model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained('shares')->cascadeOnDelete();
            $table->string('engine', 16);
            $table->string('model', 120);
            $table->string('status', 16)->default(AnalysisStatus::Queued->value);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->decimal('overall_confidence', 4, 3)->nullable();
            $table->jsonb('result_json')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['share_id', 'status']);
            $table->index(['engine', 'model']);
            $table->index('finished_at');
        });

        Constraints::enumCheck('analysis_runs', 'engine', AnalysisEngine::class);
        Constraints::enumCheck('analysis_runs', 'status', AnalysisStatus::class);

        // Numeric integrity for pipeline trust: confidence is a 0–1 probability,
        // cost is never negative.
        DB::statement('ALTER TABLE analysis_runs ADD CONSTRAINT analysis_runs_confidence_range_check CHECK (overall_confidence IS NULL OR (overall_confidence >= 0 AND overall_confidence <= 1))');
        DB::statement('ALTER TABLE analysis_runs ADD CONSTRAINT analysis_runs_cost_nonneg_check CHECK (cost_usd IS NULL OR cost_usd >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};
