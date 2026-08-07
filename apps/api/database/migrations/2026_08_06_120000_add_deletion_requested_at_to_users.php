<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tell a self-deleted account apart from a banned one (T-050).
 *
 * Both are soft deletes, and until now `deleted_at` was the only signal — which
 * made them the same state. That is fine while nothing acts on it, and
 * dangerous the moment something does: the GDPR grace period says "sign back in
 * to cancel", and applied to a ban that is a self-service unban.
 *
 * So the deletion REQUEST gets its own timestamp. `deleted_at` keeps meaning
 * "this account is not usable"; this column means "the person asked, and the
 * clock is running".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No ->after(): Postgres ignores column ordering hints, and leaving
            // one in implies a guarantee the grammar never makes.
            $table->timestampTz('deletion_requested_at')->nullable();
        });

        // Genuinely PARTIAL, which $table->index() is not: virtually every row
        // is null, and the only reader (`reelmap:gdpr:sweep-deletions`) asks
        // exclusively about the ones that are not.
        DB::statement(
            'CREATE INDEX users_deletion_requested_at_index ON users (deletion_requested_at) '.
            'WHERE deletion_requested_at IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_deletion_requested_at_index');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deletion_requested_at');
        });
    }
};
