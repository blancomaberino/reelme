<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The nightly ledger check (T-044, 02 §3.15, 06 §4.2).
 *
 * `LedgerService::record()` already refuses to write an imbalance, so in
 * principle this can never find anything — which is exactly why it runs. The
 * invariant that "cannot" break is the one nobody notices breaking: a
 * hand-written UPDATE, a migration, a future code path that inserts rows
 * directly. Money silently not adding up is not a failure that announces itself.
 *
 * Exits NON-ZERO on a violation so the scheduler surfaces it as a failed run
 * rather than a log line nobody greps for.
 */
class VerifyLedgerInvariants extends Command
{
    protected $signature = 'reelmap:ledger:verify';

    protected $description = 'Assert every ledger transaction balances (T-044, 02 §3.15)';

    public function handle(LedgerService $ledger): int
    {
        $report = $ledger->verifyInvariants();

        if ($report->isHealthy()) {
            $this->info($report->summary());

            return self::SUCCESS;
        }

        // Logged at `critical`: this is the one alert in the system that means
        // the books are wrong, and it should page rather than accumulate.
        Log::critical('ledger.invariant_violated', [
            'unbalanced' => $report->unbalanced,
            'single_entry_transactions' => $report->singleEntryTransactions,
            'checked' => $report->checked,
        ]);

        $this->error($report->summary());

        foreach ($report->unbalanced as $row) {
            $this->line("  {$row['transaction_uuid']} ({$row['currency']}): debits {$row['debits']} ≠ credits {$row['credits']}");
        }

        foreach ($report->singleEntryTransactions as $uuid) {
            $this->line("  {$uuid}: only one entry — a half-written transaction");
        }

        return self::FAILURE;
    }
}
