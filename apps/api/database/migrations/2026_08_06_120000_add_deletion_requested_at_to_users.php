<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->timestampTz('deletion_requested_at')->nullable()->after('deleted_at');

            // The purge sweep asks "whose grace has run out" — a partial index
            // keeps that cheap on a table where virtually every row is null.
            $table->index('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn('deletion_requested_at');
        });
    }
};
