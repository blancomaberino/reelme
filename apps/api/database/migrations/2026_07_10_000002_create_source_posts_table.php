<?php

use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\PostPrivacy;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per original social post, deduplicated across sharers (02 §3.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_posts', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16);
            $table->string('external_id', 255);
            $table->string('url', 2048);
            $table->foreignId('influencer_id')->nullable()
                ->constrained('influencers')->nullOnDelete();
            $table->text('caption')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->string('privacy', 16)->default(PostPrivacy::Unknown->value);
            $table->jsonb('oembed_json')->nullable();
            $table->string('fetch_status', 16)->default(FetchStatus::Pending->value);
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampsTz();

            $table->unique(['platform', 'external_id']);
            $table->index('influencer_id');
            $table->index('fetch_status');
        });

        DB::statement(Constraints::enumCheck('source_posts', 'platform', Platform::cases()));
        DB::statement(Constraints::enumCheck('source_posts', 'privacy', PostPrivacy::cases()));
        DB::statement(Constraints::enumCheck('source_posts', 'fetch_status', FetchStatus::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('source_posts');
    }
};
