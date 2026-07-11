<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Native user reviews for a place — "ready to receive" (02 §3.8). One review per
 * (place, user); `rating` is a 1–5 star integer enforced by a CHECK. Distinct from
 * the cached Google review snippets on `places.google_reviews_json`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->smallInteger('rating');
            $table->text('body')->nullable();
            $table->timestampsTz();

            $table->unique(['place_id', 'user_id']);
            $table->index('place_id');
        });

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range_check CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
