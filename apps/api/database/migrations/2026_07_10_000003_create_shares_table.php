<?php

use App\Enums\ShareStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's act of sharing a post (02 §3.5). Pipeline state machine lives here.
 * `published_place_source_id` is deliberately NOT added here — the circular
 * shares⇄place_sources FK is broken by a later migration (T-023, 02 §6 step 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_post_id')->constrained('source_posts')->cascadeOnDelete();
            $table->string('status', 16)->default(ShareStatus::Pending->value);
            $table->text('failure_reason')->nullable();
            $table->string('shared_via', 32)->nullable(); // share_sheet | paste_url | manual
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'source_post_id']); // one share per user per post
            $table->index('status');
            $table->index('source_post_id');
        });

        DB::statement(Constraints::enumCheck('shares', 'status', ShareStatus::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('shares');
    }
};
