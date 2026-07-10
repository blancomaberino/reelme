<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stage execution records for the pipeline (04 §8). `GET /shares/{id}`'s
 * status_history is derived from these rows + shares.created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_stage_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_id')->constrained('shares')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('status', 16);
            $table->timestampTz('started_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->smallInteger('attempt')->default(1);
            $table->timestampsTz();

            $table->index(['share_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_stage_metrics');
    }
};
