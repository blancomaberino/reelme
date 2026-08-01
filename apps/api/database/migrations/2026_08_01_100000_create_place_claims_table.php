<?php

use App\Enums\ClaimStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant-owner verification (T-041, 02 §3.12).
 *
 * The load-bearing line here is the PARTIAL UNIQUE INDEX: at most one `verified`
 * claim per place, enforced by Postgres rather than by a check in application
 * code. "One verified owner per place" is the rule the whole restaurant program
 * rests on — a second owner would mean two people creating offers and drawing
 * fees against one venue — and a race between two admins approving competing
 * claims in different requests is exactly the case an application-level guard
 * misses. Pending and rejected rows are unconstrained, so competing claims can
 * pile up and be escalated (06 §2.1) instead of being rejected at insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method', 24);
            $table->string('status', 16)->default(ClaimStatus::Pending->value);
            // Per-method working state: the hashed phone OTP and its expiry, the
            // website token, the uploaded document path. Shapeless on purpose —
            // each method needs different fields and none of them are queried.
            $table->jsonb('evidence_json')->nullable();
            $table->timestamp('verified_at')->nullable();
            // Machine-readable rejection reason, mirroring influencer_claims.
            $table->string('reason', 64)->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            // Feeds the Filament queue: oldest pending first.
            $table->index(['status', 'created_at']);
        });

        // Inlined rather than bound: Postgres cannot infer a parameter's type in
        // DDL ("could not determine data type of parameter $1"). The value comes
        // from the enum, never from input, so there is nothing to inject.
        $verified = ClaimStatus::Verified->value;

        DB::statement(
            "CREATE UNIQUE INDEX place_claims_one_verified_per_place
             ON place_claims (place_id) WHERE status = '{$verified}'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('place_claims');
    }
};
