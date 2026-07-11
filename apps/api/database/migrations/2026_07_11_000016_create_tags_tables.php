<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags + place_tag pivot (02 §3.10, T-031). Tags are materialized from
 * extraction snapshots on publish; the pivot records provenance (`source`)
 * and the analysis confidence that attached the tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('name', 80);
            $table->string('slug', 96);
            $table->timestamps();

            $table->unique(['kind', 'slug']);
        });
        DB::statement(
            "ALTER TABLE tags ADD CONSTRAINT tags_kind_check CHECK (kind IN ('cuisine', 'vibe', 'dish', 'diet', 'other'))"
        );

        Schema::create('place_tag', function (Blueprint $table) {
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16)->default('extraction');
            $table->decimal('confidence', 4, 3)->nullable();

            $table->primary(['place_id', 'tag_id']);
            $table->index('tag_id');
        });
        DB::statement(
            "ALTER TABLE place_tag ADD CONSTRAINT place_tag_source_check CHECK (source IN ('extraction', 'manual', 'owner'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('place_tag');
        Schema::dropIfExists('tags');
    }
};
