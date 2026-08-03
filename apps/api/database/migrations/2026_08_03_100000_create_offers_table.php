<?php

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant offers (T-042, 02 §3.13) — what a diner redeems and the unit the
 * flat pay-per-redemption fee hangs on (06 §2.3).
 *
 * Two constraints are pushed into Postgres rather than left to the application:
 *
 * 1. The percent CHECK. `discount_value` is one integer column serving three
 *    units (see {@see OfferDiscountType}), so nothing about the column's TYPE
 *    stops a "120% off" from being written by a future importer, admin action,
 *    or backfill that never passes through the FormRequest. The narrower 5–50
 *    business rule (06 §2.2) stays in validation — it is a policy that may be
 *    relaxed per campaign; 1–100 is arithmetic that never can be.
 * 2. The window CHECK: `ends_at` after `starts_at`. An inverted window is an
 *    offer that is permanently un-redeemable but still looks live in a list.
 *
 * `quota_per_day` is NOT in 02 §3.13 — it is required by the 06 §2.2 quota
 * table and by the anti-fraud controls in 06 §3, and is added here (nullable =
 * unlimited) so `isRedeemable()` has one place to enforce both caps. Noted as a
 * deliberate addition to the data model rather than a silent divergence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            // The operator who created it. Kept even if their claim is later
            // revoked — this is an audit fact, not a live permission (ownership
            // is always re-derived from the verified place_claim).
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('discount_type', 16);
            $table->integer('discount_value');
            $table->text('terms')->nullable();
            // timestampTZ, per 02 §3.13 — and because unlike an audit stamp
            // these two are COMPARED against `now()` on every read. A naive
            // timestamp is only correct while the session timezone happens to
            // be UTC; the day that changes, every offer's window silently
            // shifts and the failure looks like "the promo ended early".
            $table->timestampTz('starts_at');
            // Null = open-ended. Every window query needs the `OR ends_at IS
            // NULL` branch; the `active()` scope owns that so no caller writes
            // it by hand.
            $table->timestampTz('ends_at')->nullable();
            $table->integer('quota_total')->nullable();
            $table->integer('quota_per_user')->default(1);
            // See the class docblock: an addition to 02 §3.13, required by 06 §2.2.
            $table->integer('quota_per_day')->nullable();
            // Counter cache over non-void redemptions (T-043 maintains it).
            $table->integer('redemptions_count')->default(0);
            $table->smallInteger('influencer_share_bps')->default(1000);
            $table->string('status', 16)->default(OfferStatus::Draft->value);
            $table->timestamps();

            // The two read paths: "this place's offers by state" (place detail,
            // the operator's list) and "what is live right now" (diner browse).
            $table->index(['place_id', 'status']);
            $table->index(['starts_at', 'ends_at']);
        });

        // Inlined, not bound: Postgres cannot infer a parameter's type in DDL
        // ("could not determine data type of parameter $1"). Both values come
        // from enum cases, never from input.
        $percent = OfferDiscountType::Percent->value;

        DB::statement(
            "ALTER TABLE offers ADD CONSTRAINT offers_percent_value_check
             CHECK (discount_type <> '{$percent}' OR discount_value BETWEEN 1 AND 100)",
        );

        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT offers_window_check
             CHECK (ends_at IS NULL OR ends_at > starts_at)',
        );

        // Quotas are caps, not counters: zero would mean "created already
        // exhausted", which is a mistake in every case we can construct.
        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT offers_quota_positive_check
             CHECK ((quota_total IS NULL OR quota_total > 0)
                AND (quota_per_day IS NULL OR quota_per_day > 0)
                AND quota_per_user > 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
