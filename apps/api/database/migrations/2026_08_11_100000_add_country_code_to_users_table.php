<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The country a user says they are in (T-110).
 *
 * `char(2)` nullable, deliberately the same type and casing as
 * `places.country_code`, so a person and a place can be compared or joined
 * without normalizing one side. Nullable because every existing row has no
 * answer and none of them should be forced into a guess — "unset" is a real
 * state the profile has to be able to return to.
 *
 * Country only, never city: the value is public on a public profile (owner
 * decision, 2026-07-29), and country is coarse enough to enable regional
 * discovery without telling strangers where somebody lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No ->after(): Postgres ignores column ordering hints, and leaving
            // one in implies a guarantee the grammar never makes (the same note
            // 2026_08_06_120000_add_deletion_requested_at_to_users carries).
            $table->char('country_code', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
