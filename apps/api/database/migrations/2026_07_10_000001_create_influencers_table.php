<?php

use App\Enums\Platform;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical influencer identity (02-data-model §3.3). Created lazily when a post
 * by an unseen author is ingested; exists independent of a Reelmap account.
 */
return new class extends Migration
{
    public function up(): void
    {
        // citext is enabled by the 0000_ extensions migration (guaranteed to run
        // first); re-assert only the one this migration's ALTER depends on.
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('influencers', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            $table->string('handle'); // → citext below (normalized, no leading @)
            $table->string('display_name', 255)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->foreignId('claimed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->integer('follower_count_cached')->nullable();
            $table->timestampTz('follower_count_synced_at')->nullable();
            $table->timestampsTz();

            $table->index('claimed_by_user_id');
        });

        DB::statement('ALTER TABLE influencers ALTER COLUMN handle TYPE citext');
        DB::statement('ALTER TABLE influencers ADD CONSTRAINT influencers_platform_handle_unique UNIQUE (platform, handle)');
        Constraints::enumCheck('influencers', 'platform', Platform::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('influencers');
    }
};
