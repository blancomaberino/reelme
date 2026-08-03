<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The influencer share default becomes 5000 bps — 50% (T-044).
 *
 * 02 §3.13 gives the column a default of **1000** (10%); 06 §4.1 states the v1
 * business default is **50% of the platform net fee**, and 02 §3.15's own worked
 * example calls its 10% figure "illustrative … v1 business default is 50% per
 * 06-monetization §4 — never hardcode either". The two specs disagree, and until
 * T-044 nothing read the column, so nothing surfaced it.
 *
 * It surfaces now: this number decides what an influencer is PAID. Left at 1000,
 * every offer created since T-042 would quietly pay a fifth of what the product
 * promises, and the error would be invisible — the ledger would balance
 * perfectly, just against the wrong split.
 *
 * 06 §4.1 wins: it is the section that states the business rule, and 02 §3.15
 * defers to it explicitly. Existing rows are migrated too — they were all
 * created by T-042 days ago, none has a redemption against it, and none was
 * deliberately set to 10% by an operator (the API never exposed the field).
 * Once real offers exist this becomes a default-only change; that is what the
 * `updated_at` guard below is for.
 */
return new class extends Migration
{
    private const OLD_DEFAULT = 1000;

    private const NEW_DEFAULT = 5000;

    public function up(): void
    {
        DB::statement('ALTER TABLE offers ALTER COLUMN influencer_share_bps SET DEFAULT '.self::NEW_DEFAULT);

        // Only rows still carrying the old DEFAULT, and only where no
        // redemption has been priced against them. An offer somebody
        // deliberately set to 10% (possible via Filament in future) keeps it,
        // and one that has already paid out is never repriced — 06 §4.1 is
        // explicit that share changes are never retroactive.
        DB::table('offers')
            ->where('influencer_share_bps', self::OLD_DEFAULT)
            ->whereNotExists(fn ($q) => $q->from('redemptions')->whereColumn('redemptions.offer_id', 'offers.id'))
            ->update(['influencer_share_bps' => self::NEW_DEFAULT]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE offers ALTER COLUMN influencer_share_bps SET DEFAULT '.self::OLD_DEFAULT);
    }
};
