<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User blocking (T-054, IR-6 / Apple Guideline 1.2).
 *
 * A launch blocker for a UGC app: reporting alone is not enough, a person must
 * be able to make someone else's content stop reaching them without waiting on
 * a moderator.
 *
 * Users only — NOT the polymorphic followee shape. An influencer is an
 * attribution identity for a real account someone else operates; "block" there
 * would mean hiding a whole creator's contribution to community places, which
 * is a different feature (and closer to the T-071 hidden-places mechanism than
 * to this one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();

            // Cascade both ways: a blocked account that is later erased (T-050)
            // must not leave a dangling row that keeps filtering queries for a
            // user who no longer exists, and a blocker's own deletion takes
            // their list with it — it is their data.
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // One row per direction. Blocking is NOT symmetric as a record even
            // though its effects are: A blocking B is A's decision and must be
            // undoable by A alone, so B blocking A is a second, separate row.
            $table->unique(['blocker_id', 'blocked_id']);

            // The hot read is "everyone this viewer has blocked OR who has
            // blocked them", evaluated on the feed and every profile. Both
            // directions get an index; the unique above covers (blocker, …).
            $table->index('blocked_id');
        });

        // Nobody can block themselves. Enforced in the DB rather than only in a
        // FormRequest: a self-block would silently empty the blocker's own feed
        // and profile, and the bug would look like data loss.
        DB::statement('ALTER TABLE user_blocks ADD CONSTRAINT user_blocks_not_self CHECK (blocker_id <> blocked_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
    }
};
