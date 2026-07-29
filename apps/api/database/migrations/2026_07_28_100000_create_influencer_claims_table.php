<?php

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Support\Database\Constraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable state for the influencer claiming flow (T-038, 06 §5.1). A row per
 * (influencer, user) pair captures the in-progress bio-code token and the final
 * verdict — rejection and admin dispute-resolution both need to persist, so a
 * table beats a cache entry. `influencers.claimed_by_user_id` remains the single
 * source of truth for "who owns this identity"; this table is the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influencer_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('influencer_id')->constrained('influencers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 16);
            $table->string('status', 16)->default(ClaimStatus::Pending->value);
            // The one-time bio-code token (null for an OAuth claim, which needs no code).
            $table->string('token', 64)->nullable();
            // Machine-readable disposition reason (e.g. claimed_by_other, admin_override).
            $table->string('reason', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // One claim record per (influencer, user): regenerating a token or
            // retrying updates the same row (updateOrCreate target).
            $table->unique(['influencer_id', 'user_id']);
            // Dispute detection + the admin queue filter scan by influencer+status.
            $table->index(['influencer_id', 'status']);
        });

        Constraints::enumCheck('influencer_claims', 'method', ClaimMethod::class);
        Constraints::enumCheck('influencer_claims', 'status', ClaimStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('influencer_claims');
    }
};
