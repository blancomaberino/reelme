<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ground-truth corpus for model evals (T-024, 04 §7): one row per corrected leaf
 * field, capturing the model's value and the user's value. Values are jsonb so
 * array/object fields (dishes, tags) diff and store cleanly. Joined against
 * `analysis_runs.model` for per-model accuracy dashboards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained('shares')->cascadeOnDelete();
            $table->string('field_path', 120);
            $table->jsonb('model_value')->nullable();
            $table->jsonb('user_value')->nullable();
            $table->timestampsTz();

            $table->index('share_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_corrections');
    }
};
