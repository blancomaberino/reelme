<?php

use App\Enums\PayoutStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Connect transfers to influencers (T-045, 02 §3.16, 06 §4.3).
 *
 * Two unique indexes, both guarding against paying the same money twice:
 *
 * 1. **`stripe_transfer_id`** (partial, where not null) — one row per Stripe
 *    Transfer. A redelivered webhook cannot mint a second payout row for a
 *    transfer we already recorded.
 * 2. **`(user_id, period_start, period_end, currency)`** — one payout per
 *    earner per period. The monthly run is a scheduled command that can be
 *    re-run by hand after a partial failure, and this is what makes that safe.
 *
 * Amounts are minor units and EUR-only in v1 (06 §4.3), but the currency is
 * stored rather than assumed — a payout row is a financial record, and one that
 * cannot state its own currency is unreadable the day a second one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            // Never cascades: deleting a user must not delete the record that we
            // sent them money.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('stripe_transfer_id')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 16)->default(PayoutStatus::Pending->value);
            // The earnings window this settles — what an influencer sees on a
            // statement, and what makes the per-period unique index meaningful.
            $table->date('period_start');
            $table->date('period_end');
            $table->text('failure_reason')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->unique(['user_id', 'period_start', 'period_end', 'currency'], 'payouts_one_per_user_period');
        });

        // Partial: many payouts are legitimately without a transfer id (pending,
        // or failed before Stripe accepted), and a plain unique index would let
        // exactly one of them exist.
        DB::statement('CREATE UNIQUE INDEX payouts_stripe_transfer_id_unique ON payouts (stripe_transfer_id) WHERE stripe_transfer_id IS NOT NULL');

        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_period_order_check CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
