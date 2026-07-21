<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirm-before-publish (T-098). An uncertain share (the model wasn't confident)
 * no longer dead-ends as a user chore in `review`: the sharer gets an optional
 * pre-publish confirm, and if they skip it or abandon it the best guess is
 * published anyway and handed to an admin to clean up — never lost, never the
 * sharer's job.
 *
 * - `shares.flagged_uncertain` — set when a share is published as a best guess
 *   (skip / abandon sweep) rather than confirmed. Durable across the re-dispatched
 *   resolve→publish (ResolvePlace rewrites `review_meta_json`, so the intent can't
 *   ride there). PlacePublisher reads it to flag the resulting place.
 * - `places.needs_admin_review` — the place-level moderation flag the admin queue
 *   scans. Orthogonal to `status` (a flagged place is still Pending/Active and on
 *   the map); cleared once a confident/user-confirmed source or an admin resolves
 *   it. PlacePublisher keeps it in sync: `flagged_uncertain && !user_confirmed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table): void {
            $table->boolean('flagged_uncertain')->default(false)->after('user_confirmed');
        });

        Schema::table('places', function (Blueprint $table): void {
            $table->boolean('needs_admin_review')->default(false)->after('status');
            // Indexed for the admin queue's `WHERE needs_admin_review = true` scan.
            $table->index(['needs_admin_review'], 'places_needs_admin_review_index');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropIndex('places_needs_admin_review_index');
            $table->dropColumn('needs_admin_review');
        });

        Schema::table('shares', function (Blueprint $table): void {
            $table->dropColumn('flagged_uncertain');
        });
    }
};
