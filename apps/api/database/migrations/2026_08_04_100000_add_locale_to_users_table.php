<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The language a user's PUSH notifications are written in.
 *
 * The notification center renders its own copy client-side from `data.type`, so
 * it follows the in-app language toggle instantly. A push has no client to
 * render it — the string is composed in a queued worker minutes later — so the
 * server has to know the language, and this column is the only place it can
 * live. Without it every push went out in whatever language the developer typed
 * into the class (Spanish for the pipeline ones, English for the social ones).
 *
 * Defaults to `es`: the product launches Uruguay-first, and a user whose device
 * never reported a locale is far more likely to read Spanish than English.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('es')->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
