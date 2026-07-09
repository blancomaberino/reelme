<?php

use App\Enums\MediaKind;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downloaded/derived media for a source post (02 §3.6): video, audio, keyframes,
 * thumbnails, user screen recordings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_post_id')->constrained('source_posts')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('storage_path', 2048);
            $table->string('disk', 32)->default('s3');
            $table->string('mime', 127);
            $table->bigInteger('bytes')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->char('sha256', 64);
            $table->integer('frame_at_ms')->nullable(); // keyframes only
            $table->timestampsTz();

            $table->index(['source_post_id', 'kind']);
            $table->unique(['sha256', 'source_post_id']);
            $table->index('sha256');
        });

        Constraints::enumCheck('media_assets', 'kind', MediaKind::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
