<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The OUTCOME of the signup age check, and deliberately nothing more (T-113).
 *
 * The terms have always said "at least 13 years old" and nothing enforced it,
 * so the claim was true of the document and false of the app. The gate that
 * fixes that asks for a date of birth — and then throws it away.
 *
 * That is the whole design. Storing the date would mean collecting a new
 * identifier from every person who signs up, in an app whose privacy policy
 * makes a point of collecting only what it needs, in order to answer a question
 * that is already answered the moment it is asked. `users.birthdate` still
 * exists and is still OPTIONAL profile personalization the user fills in for
 * themselves; this column is not that, and the two must not be conflated.
 *
 * Nullable because every account that predates this migration was created
 * without a check, and backfilling a timestamp would be inventing a
 * verification that never happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('age_verified_at')->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('age_verified_at');
        });
    }
};
