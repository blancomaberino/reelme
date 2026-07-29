<?php

namespace App\Events;

use App\Models\Influencer;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a user's claim over an influencer identity verifies (T-038). The M4
 * ledger subscribes to this to release the influencer's escrowed earnings
 * (06 §5.3) — do NOT touch money here; emit the event and stop, or the M4
 * retroactive grants will need a backfill.
 */
class InfluencerClaimed
{
    use Dispatchable;

    public function __construct(
        public readonly Influencer $influencer,
        public readonly User $user,
    ) {}
}
