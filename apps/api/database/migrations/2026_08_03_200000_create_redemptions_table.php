<?php

use App\Enums\RedemptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issued offer codes (T-043, 02 §3.14) — the payable event of the whole
 * business model (06 §1, §3).
 *
 * Three constraints are pushed into Postgres, because each one guards money and
 * application code cannot win the race alone:
 *
 * 1. **`unique(code)`** — the code IS the bearer token a restaurant honours.
 * 2. **Partial unique `(offer_id, user_id) where status = 'issued'`** — 06 §3's
 *    "one active redemption per user per offer". Two concurrent issue requests
 *    from the same diner both pass an application-level check and both insert;
 *    only the index stops the second. This is the difference between a diner
 *    holding one code and holding ten.
 * 3. **RESTRICT on `offer_id`** — an offer with redemptions against it cannot be
 *    deleted. T-042 already archives rather than deletes; this makes the
 *    invariant true even for a direct DB write, because a fee charged against a
 *    vanished offer cannot be audited or disputed.
 *
 * The attribution FKs are SET NULL rather than RESTRICT, which looks like the
 * opposite policy and is not: 02 §3.14 pairs them with a denormalised copy. The
 * money record must survive a deleted share, and T-044's ledger rows are the
 * immutable copy — so nothing joins through `shares` at payout time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // char(10), not varchar: every code is exactly ten Crockford base32
            // characters, and a fixed width makes a truncated one a write error
            // rather than a lookup that quietly misses.
            $table->char('code', 10)->unique();
            // The signed token the QR encodes — a forged QR built from a guessed
            // code fails its signature even though the code column is short.
            $table->string('qr_payload', 255);
            $table->string('status', 16)->default(RedemptionStatus::Issued->value);
            $table->timestampTz('issued_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Frozen at issue (02 §5): last-touch attribution must not move when
            // the underlying share is edited, re-analysed, or deleted.
            $table->foreignId('attributed_influencer_id')->nullable()->constrained('influencers')->nullOnDelete();
            $table->foreignId('attributed_share_id')->nullable()->constrained('shares')->nullOnDelete();
            // Set at REDEMPTION, not at issue: pricing an offer when the code is
            // handed out would bill an old rate for a visit made after a
            // repricing (06 §2.3). Minor units, never a float.
            $table->bigInteger('fee_amount')->nullable();
            $table->char('currency', 3)->nullable();
            // Geofence outcome (06 §3): recorded rather than silently passed, so
            // a venue with a broken GPS reading is visible to an admin instead
            // of just failing its staff.
            $table->boolean('geofence_ok')->nullable();
            $table->integer('geofence_distance_m')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status']);
            $table->index('user_id');
            $table->index('attributed_influencer_id');
            $table->index('attributed_share_id');
            // The expiry sweep's work list: overdue rows still marked issued.
            $table->index(['status', 'expires_at']);
        });

        // Inlined, not bound: Postgres cannot infer a parameter's type in DDL.
        // The value comes from the enum, never from input.
        $issued = RedemptionStatus::Issued->value;

        DB::statement(
            "CREATE UNIQUE INDEX redemptions_one_active_per_user_offer
             ON redemptions (offer_id, user_id) WHERE status = '{$issued}'",
        );

        // A redeemed row must carry when and by whom; an unredeemed one must
        // carry neither. Without this, a partially-written verify (or a manual
        // fix-up) leaves a row that is billable but cannot say who honoured it.
        DB::statement(
            "ALTER TABLE redemptions ADD CONSTRAINT redemptions_redeemed_fields_check
             CHECK ((status = 'redeemed') = (redeemed_at IS NOT NULL))",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
