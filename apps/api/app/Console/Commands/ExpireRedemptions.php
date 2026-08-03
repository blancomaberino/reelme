<?php

namespace App\Console\Commands;

use App\Enums\RedemptionStatus;
use App\Models\Redemption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retire codes nobody used (T-043, 06 §2.3).
 *
 * Only `redeemed` is billable, so an unvisited code must not sit at `issued`
 * forever looking like an open obligation — to the operator reading their log,
 * to the quota arithmetic, and eventually to whoever reconciles the ledger.
 *
 * This is HYGIENE, not enforcement. Nothing downstream trusts the column alone:
 * `RedemptionVerifier` re-checks the clock on every scan, and `Redemption::live`
 * evaluates it on every read — because between a window closing at 3am and this
 * command running there is a real interval in which the row is stale, and a code
 * honoured in that gap would be billed for a visit 06 §2.3 says is free.
 */
class ExpireRedemptions extends Command
{
    protected $signature = 'reelmap:redemptions:expire';

    protected $description = 'Mark overdue issued redemptions as expired (T-043, 06 §2.3)';

    public function handle(): int
    {
        // Chunked by id rather than one big UPDATE: the table grows with every
        // offer ever claimed, and a single statement would hold locks across all
        // of it while the verify path is trying to take a row lock of its own.
        $total = 0;

        Redemption::query()
            ->overdue()
            ->select('id')
            ->chunkById(500, function ($rows) use (&$total): void {
                $updated = Redemption::query()
                    ->whereIn('id', $rows->pluck('id'))
                    // Re-checked inside the write: a code redeemed between the
                    // read and here must NOT be flipped to expired, or a
                    // restaurant is billed for a visit the row then denies.
                    ->where('status', RedemptionStatus::Issued)
                    ->update([
                        'status' => RedemptionStatus::Expired,
                        'updated_at' => now(),
                    ]);

                $total += $updated;
            });

        if ($total > 0) {
            Log::info('redemptions.expired', ['count' => $total]);
        }

        $this->info("Expired {$total} redemption(s).");

        return self::SUCCESS;
    }
}
