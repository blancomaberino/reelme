<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal place collections (T-062). A user groups saved places into named
 * lists (e.g. a country's spots to visit). Owner-scoped; `is_public` + `slug`
 * back the shareable read (T-063). Slug is unique per owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 160);
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            // Public share lookups hit slug directly.
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_lists');
    }
};
