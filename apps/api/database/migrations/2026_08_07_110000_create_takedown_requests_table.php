<?php

use App\Enums\TakedownRequesterRole;
use App\Enums\TakedownStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rightsholder / influencer takedown notices (T-049, IR-2, R-07, ADR-010).
 *
 * Entered by ops from the `dmca@` inbox — there is deliberately no public API.
 * A self-service takedown endpoint is a weapon: anyone could unpublish anyone
 * else's places by asserting a copyright claim, and verifying the claim is the
 * part that needs a human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('takedown_requests', function (Blueprint $table) {
            $table->id();

            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('requester_role', 16);

            // The post being claimed. Nullable FK: a notice often arrives as a
            // bare URL before anyone has matched it to a row, and refusing to
            // log it until then is how notices get lost.
            $table->foreignId('source_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_url', 2048)->nullable();

            $table->text('notes')->nullable();
            $table->string('status', 16)->default(TakedownStatus::Received->value);

            $table->foreignId('actioned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('actioned_at')->nullable();
            // What the action actually did, for the response letter and for
            // anyone auditing later.
            $table->jsonb('outcome_json')->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index('source_post_id');
        });

        Constraints::enumCheck('takedown_requests', 'status', TakedownStatus::class);
        Constraints::enumCheck('takedown_requests', 'requester_role', TakedownRequesterRole::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('takedown_requests');
    }
};
