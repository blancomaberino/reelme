<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loosen the redeemed-fields CHECK so a VOID keeps its timestamp (T-044).
 *
 * T-043 wrote `(status = 'redeemed') = (redeemed_at IS NOT NULL)` — an iff. It
 * held for every state that existed then, and broke the moment 06 §4.4's void
 * arrived: voiding a disputed redemption moves it to `void` while `redeemed_at`
 * stays set, because IT WAS REDEEMED. That fact is the whole point of the audit
 * trail — a restaurant asking "why was this on my invoice" needs to see that it
 * was honoured on the 3rd and reversed on the 5th, not a row that denies the
 * visit ever happened.
 *
 * The invariant that actually matters, stated directly:
 *
 * - `redeemed` REQUIRES a timestamp — a billable row must say when.
 * - `issued` and `expired` FORBID one — nothing was honoured.
 * - `void` allows either, because a void can follow a redemption (disputed) or
 *   cancel a code that was never presented (moderation).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE redemptions DROP CONSTRAINT IF EXISTS redemptions_redeemed_fields_check');

        DB::statement(<<<'SQL'
            ALTER TABLE redemptions ADD CONSTRAINT redemptions_redeemed_fields_check
            CHECK (
                (status <> 'redeemed' OR redeemed_at IS NOT NULL)
                AND (status NOT IN ('issued', 'expired') OR redeemed_at IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE redemptions DROP CONSTRAINT IF EXISTS redemptions_redeemed_fields_check');
        DB::statement(<<<'SQL'
            ALTER TABLE redemptions ADD CONSTRAINT redemptions_redeemed_fields_check
            CHECK ((status = 'redeemed') = (redeemed_at IS NOT NULL))
        SQL);
    }
};
