<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crowd-sourced corrections to a place's business info (T-083).
 *
 * A suggestion is a *proposal*; `place_edits` remains the record of what was
 * actually applied, and an approved row points at the one it produced. The
 * diff is stored in the same `{field: {from, to}}` shape as `place_edits.changes`
 * so the moderation queue, the owner's list and the audit trail all render the
 * same structure — and `from` is what the submitter was looking at, which is
 * what a reviewer needs to judge a stale proposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_edit_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            // Null once the submitter is erased (T-050): the proposal is the
            // business's record by then, exactly as `place_edits.user_id`.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // {"phone": {"from": "…", "to": "…"}, …} — same shape as place_edits.
            $table->jsonb('changes');
            $table->string('status', 16)->default('pending');
            // A verified operator's own edit, which applied on submit. Kept as a
            // row so the venue's history reads as one list rather than two.
            $table->boolean('is_owner_submission')->default(false);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Why it was rejected — shown back to nobody yet, but the reviewer's
            // reasoning is the part of a moderation decision that gets lost.
            $table->text('reason')->nullable();
            // The audit row an approval produced, so "what did this suggestion
            // actually change" is a join and not a guess. Null while pending,
            // and for an approval whose fields had already been fixed by then.
            $table->foreignId('place_edit_id')->nullable()->constrained('place_edits')->nullOnDelete();
            $table->timestamps();

            // The queue's own query: one place's proposals, by state.
            $table->index(['place_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE place_edit_suggestions ADD CONSTRAINT place_edit_suggestions_status_check '
            ."CHECK (status IN ('pending', 'approved', 'rejected'))"
        );

        // One OPEN proposal per person per place. Without it, a form resubmitted
        // twice becomes two near-identical rows a moderator has to reconcile by
        // hand; with it, the API can update the open row and the database is the
        // thing guaranteeing there is only ever one to update. Settled rows are
        // history, so they are outside the index.
        DB::statement(
            'CREATE UNIQUE INDEX place_edit_suggestions_one_pending_per_user '
            ."ON place_edit_suggestions (place_id, user_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('place_edit_suggestions');
    }
};
