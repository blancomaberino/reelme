<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redemption fee (06 §2.3)
    |--------------------------------------------------------------------------
    | The flat fee a restaurant pays per VERIFIED redemption, in MINOR units.
    | Never a float — this number is multiplied by a basis-point share and then
    | summed across a month's invoice, and a float would drift a cent per
    | thousand redemptions.
    |
    | 06 §2.3 fixes the v1 default at €3.00 and the configurable band at
    | €2.00–€4.00. The band is asserted at posting time rather than trusted:
    | a typo in an env var would otherwise bill every restaurant wrongly and
    | look exactly like normal operation.
    */
    'redemption_fee_minor' => (int) env('REELMAP_REDEMPTION_FEE_MINOR', 300),

    'redemption_fee_min_minor' => 200,

    'redemption_fee_max_minor' => 400,

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | EUR only in v1 (06 §4.3). The ledger is nonetheless multi-currency by
    | construction — the balance invariant is per (transaction, currency) — so
    | adding a second one is a data change, not a migration.
    */
    'currency' => env('REELMAP_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Influencer share (06 §4.1)
    |--------------------------------------------------------------------------
    | The default basis points of the platform fee shared with the attributed
    | influencer, used only when an offer does not carry its own. In practice
    | every offer freezes `influencer_share_bps` at creation (T-042) and THAT is
    | what is paid — changing this value must never alter what an already-issued
    | offer earns, which is why the per-offer copy exists at all.
    */
    'default_influencer_share_bps' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Payout threshold (06 §4.3)
    |--------------------------------------------------------------------------
    | €25.00 payable before a transfer is worth its fees. Below it the balance
    | rolls over — nothing is lost, and the wallet says so rather than showing a
    | disabled button with no explanation.
    */
    'payout_threshold_minor' => (int) env('REELMAP_PAYOUT_THRESHOLD_MINOR', 2500),

];
