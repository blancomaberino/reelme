<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic follows (02 §3.11, T-037) + follower counter caches. The
 * followee is a morph (user | influencer aliases — never FQCNs), so no DB FK
 * on followee_id; orphan rows are pruned by the deletion flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('followee_type', 32);
            $table->unsignedBigInteger('followee_id');
            $table->timestampsTz();

            $table->unique(['follower_user_id', 'followee_type', 'followee_id']);
            $table->index(['followee_type', 'followee_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('following_count')->default(0);
        });
        Schema::table('influencers', function (Blueprint $table) {
            $table->unsignedInteger('followers_count')->default(0);
        });

        // Database notifications (NewFollower rides this; Expo push is T-027).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('influencers', function (Blueprint $table) {
            $table->dropColumn('followers_count');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['followers_count', 'following_count']);
        });
        Schema::dropIfExists('follows');
    }
};
