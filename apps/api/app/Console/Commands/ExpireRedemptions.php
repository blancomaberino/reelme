<?php

namespace App\Console\Commands;

use App\Enums\RedemptionStatus;
use App\Models\Redemption;
use App\Services\Redemptions\OfferQuotaCounter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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

    public function handle(OfferQuotaCounter $quota): int
    {
        // Chunked by id rather than one big UPDATE: the table grows with every
        // offer ever claimed, and a single statement would hold locks across all
        // of it while the verify path is trying to take a row lock of its own.
        $total = 0;

        Redemption::query()
            ->overdue()
            ->select(['id', 'offer_id'])
            ->chunkById(500, function ($rows) use ($quota, &$total): void {
                // A chunk is a slice of the id range, not of one promotion, so
                // the codes in it belong to whichever offers happen to fall in
                // that range — and the counter they release is per offer.
                foreach ($rows->groupBy('offer_id') as $offerId => $codes) {
                    $total += $this->expireGroup($quota, (int) $offerId, $codes);
                }
            });

        if ($total > 0) {
            Log::info('redemptions.expired', ['count' => $total]);
        }

        $this->info("Expired {$total} redemption(s).");

        return self::SUCCESS;
    }

    /**
     * Retire one offer's lapsed codes and hand back exactly the slots they held.
     *
     * The flip and the release commit together or not at all. As two
     * auto-committed statements, a kill between them leaves the codes `expired`
     * with the offer still holding their slots: it reads sold out, drops off the
     * map, and nothing self-heals — the reconciler only reports.
     *
     * Per offer group, deliberately not per chunk: a chunk-wide transaction
     * would hold row locks on up to 500 offers, which is exactly what the verify
     * path is waiting for.
     *
     * @param  Collection<int, Redemption>  $codes
     * @return int how many actually flipped
     */
    private function expireGroup(OfferQuotaCounter $quota, int $offerId, Collection $codes): int
    {
        return DB::transaction(function () use ($quota, $offerId, $codes): int {
            $updated = Redemption::query()
                ->whereIn('id', $codes->pluck('id'))
                // Re-checked inside the write: a code redeemed between the read
                // and here must NOT be flipped to expired, or a restaurant is
                // billed for a visit the row then denies.
                ->where('status', RedemptionStatus::Issued)
                ->update([
                    'status' => RedemptionStatus::Expired,
                    'updated_at' => now(),
                ]);

            // What the re-check actually flipped, never what we asked it to: a
            // code redeemed in that gap is still holding its slot, and releasing
            // it would hand the offer a free redemption.
            $quota->release($offerId, $updated);

            return $updated;
        });
    }
}
