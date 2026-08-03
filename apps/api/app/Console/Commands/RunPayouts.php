<?php

namespace App\Console\Commands;

use App\Enums\LedgerAccount;
use App\Exceptions\PayoutFailed;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly payout run (T-045, 06 §4.3).
 *
 * Pays every influencer whose payable balance clears the threshold and whose
 * Connect account Stripe will accept. The design constraint that shapes it:
 * **one earner's problem must never stop the others being paid.** A failed KYC,
 * a Stripe outage on one account, a negative balance from a void — each is
 * caught per user, recorded, and the run continues. A run that aborts on the
 * first exception is a run that pays whoever happens to sort first.
 *
 * `--dry-run` lists what WOULD move without touching Stripe or the ledger,
 * because "what is this about to do" is the question anyone sensibly asks before
 * a command that sends money.
 */
class RunPayouts extends Command
{
    protected $signature = 'reelmap:payouts:run {--dry-run : List eligible payouts without sending anything}';

    protected $description = 'Pay out influencer balances above the threshold (T-045, 06 §4.3)';

    public function handle(LedgerService $ledger, PayoutService $payouts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $threshold = $payouts->threshold();
        $currency = (string) config('monetization.currency');

        // One query for every party's balance rather than one per user — and it
        // deliberately excludes escrow, so a run can never sweep money that
        // belongs to nobody yet (06 §5.3).
        $balances = $ledger->balancesByUser(LedgerAccount::InfluencerEarnings, $currency);
        $eligible = array_filter($balances, fn (int $balance) => $balance >= $threshold);

        if ($eligible === []) {
            $this->info('No balances above the threshold.');

            return self::SUCCESS;
        }

        $paid = 0;
        $skipped = 0;

        foreach ($eligible as $userId => $balance) {
            $user = User::query()->find($userId);

            if ($user === null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("would pay {$user->username}: {$balance} {$currency}");

                continue;
            }

            try {
                $payout = $payouts->request($user);
                $this->info("paid {$user->username}: {$payout->amount} {$currency} ({$payout->stripe_transfer_id})");
                $paid++;
            } catch (PayoutFailed $e) {
                // Expected and per-user: unfinished KYC, a Stripe refusal. Not a
                // reason to stop paying everyone else.
                $this->warn("skipped {$user->username}: {$e->details()['reason']}");
                $skipped++;
            } catch (Throwable $e) {
                // Unexpected — logged loudly, and the run still continues.
                Log::error('payouts.run_failed_for_user', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
                $this->error("failed {$user->username}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info($dryRun
            ? count($eligible).' payout(s) would run.'
            : "Paid {$paid}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
