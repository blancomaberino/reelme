<?php

use App\Enums\PayoutStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a failed payout be retried in the same period (T-045).
 *
 * The original `(user_id, period_start, period_end, currency)` unique index
 * covered EVERY row regardless of status, which contradicted the design it was
 * written to support. A `failed` payout releases its ledger hold — the money is
 * payable again — but the dead row kept occupying the unique key, so the retry
 * was rejected. Worse, `PayoutService` reports any collision as
 * `insufficient_balance`, so an influencer whose transfer Stripe bounced was
 * told they had no money, permanently, for the rest of the month.
 *
 * Scoped to the states that actually hold funds. `failed` and `reversed` are
 * terminal and released, so they no longer block anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function ($table) {
            $table->dropUnique('payouts_one_per_user_period');
        });

        $live = collect(PayoutStatus::cases())
            ->filter(fn (PayoutStatus $s) => $s->holdsFunds())
            ->map(fn (PayoutStatus $s) => "'{$s->value}'")
            ->implode(', ');

        DB::statement(
            "CREATE UNIQUE INDEX payouts_one_live_per_user_period
             ON payouts (user_id, period_start, period_end, currency)
             WHERE status IN ({$live})",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payouts_one_live_per_user_period');

        Schema::table('payouts', function ($table) {
            $table->unique(['user_id', 'period_start', 'period_end', 'currency'], 'payouts_one_per_user_period');
        });
    }
};
