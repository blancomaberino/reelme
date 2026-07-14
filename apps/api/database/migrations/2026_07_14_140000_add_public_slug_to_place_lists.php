<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shareable lists (T-063). A list's `slug` is unique only PER OWNER, so it can't
 * address a list globally. `public_slug` is a globally-unique, unguessable-ish
 * token minted the first time a list goes public and kept stable thereafter, so
 * `GET /lists/{public_slug}` + `reelmap://list/{public_slug}` resolve one exact
 * list. Null for lists that were never shared (Postgres treats NULLs as distinct
 * under the unique index, so many un-shared lists coexist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_lists', function (Blueprint $table): void {
            $table->string('public_slug', 180)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('place_lists', function (Blueprint $table): void {
            $table->dropUnique(['public_slug']);
            $table->dropColumn('public_slug');
        });
    }
};
