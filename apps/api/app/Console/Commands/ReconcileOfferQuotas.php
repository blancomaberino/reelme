<?php

namespace App\Console\Commands;

use App\Models\Redemption;
use App\Services\Redemptions\OfferQuotaCounter;
use App\Services\Redemptions\OfferQuotaReconciler;
use App\Services\Redemptions\QuotaDriftReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled check on the offer quota counter cache (T-127, 06 §2.2).
 *
 * {@see OfferQuotaCounter} is the only writer of `offers.redemptions_count` and
 * it is careful, so in principle this can never find anything — which is exactly
 * why it runs. The column shipped in T-042 and went unwritten until T-127; a
 * counter cache that nobody audits is how that happens twice, and the failure is
 * silent in both directions. Too high and the map stops showing a venue that is
 * still paying to be there; too low and the venue serves past its own cap.
 *
 * It asks TWO questions, because a counter can be arithmetically perfect and
 * still wrong about the world:
 *
 * 1. Does the counter match the redemption rows? ({@see auditCounters()})
 * 2. Are those rows themselves still entitled to the slots they hold?
 *    ({@see auditLapsedCodes()})
 *
 * They are reported separately and never folded together: the first is drift
 * `--fix` repairs in one statement, the second is a stalled background sweep
 * that no recompute can touch.
 *
 * Report-only by default. `--fix` is the deliberate, human-invoked repair.
 * A non-zero exit is only meaningful because the schedule entry in
 * `routes/console.php` attaches an `onFailure` handler — `schedule:run` itself
 * discards the code.
 */
class ReconcileOfferQuotas extends Command
{
    /**
     * How long a code may sit lapsed-but-unswept before it means the sweep died.
     *
     * `reelmap:redemptions:expire` runs hourly, so at any moment there is up to
     * an hour of legitimately-lapsed codes still holding their slots — flagging
     * a non-zero count would fire on every single run, and a check that always
     * fails is a check nobody reads. Two hours is one missed sweep plus slack
     * for a long or overlapping run; a genuinely stopped sweep leaves codes
     * lapsed for days and clears this by a wide margin.
     */
    private const SWEEP_STALL_HOURS = 2;

    protected $signature = 'reelmap:offers:reconcile-quotas {--fix : Write the recomputed counts back over the drifting offers}';

    protected $description = 'Recompute offers.redemptions_count from the redemption rows and report drift (T-127, 06 §2.2)';

    public function handle(OfferQuotaReconciler $reconciler): int
    {
        // Both run, always. The second condition is the one an operator is least
        // likely to suspect, so it must not be hidden behind an early return
        // from the first.
        $countersTrue = $this->auditCounters($reconciler);
        $sweepKeepingUp = $this->auditLapsedCodes();

        return $countersTrue && $sweepKeepingUp ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Compare the counter against the rows, and optionally correct it.
     *
     * @return bool whether this run leaves the counters true
     */
    private function auditCounters(OfferQuotaReconciler $reconciler): bool
    {
        $report = $reconciler->reconcile();

        if ($report->isHealthy()) {
            $this->info($report->summary());

            return true;
        }

        // A COUNT and a bounded sample, never `$report->drifting` itself — see
        // QuotaDriftReport::SAMPLE_SIZE. Computed once and reused: the report is
        // immutable, and three calls would invite the listing, the drift log and
        // the repair log to show three different sets.
        $sample = $report->sample();

        // `warning`, not the ledger's `critical`. The ledger pages because the
        // BOOKS are wrong: money is misrecorded and the only remedy is a
        // reversing entry written by a human who understands what happened.
        // This is money-adjacent but not that — the redemption rows are intact
        // and remain the source of truth, so the true number is never lost and
        // `--fix` below recomputes it in one statement. Nobody should be woken
        // up for a condition the command itself can repair. It also keeps this
        // on the same channel `OfferQuotaCounter::release()` already logs the
        // same condition to from the write side; two severities for one fact
        // would split the alert.
        Log::warning('offer.quota_counter_drift', [
            'source' => 'reconcile', // see OfferQuotaCounter::release()
            'checked' => $report->checked,
            'drifting' => count($report->drifting),
            'sample' => $sample,
            'fix' => (bool) $this->option('fix'),
        ]);

        $this->error($report->summary());
        $this->listDrift($sample, $report->omitted());

        if (! $this->option('fix')) {
            return false;
        }

        $this->repairDrift($reconciler, $report, $sample);

        // True even though drift was found. The exit code answers "did this run
        // leave the counters true?", and a `--fix` run did. Failing here would
        // make a deliberate repair look like a broken command, and would page on
        // every successful self-heal if this were ever scheduled with `--fix`.
        // The drift itself is not swallowed — it is on stdout above and in both
        // log lines, which is where the record of it belongs.
        return true;
    }

    /**
     * Write the recomputed counts back, and leave a record that we did.
     *
     * @param  list<array{offer_id: int, counter: int, actual: int}>  $sample
     */
    private function repairDrift(OfferQuotaReconciler $reconciler, QuotaDriftReport $report, array $sample): void
    {
        // The report is handed to the repair rather than letting it find its own
        // drift set, so the offers listed above are exactly the offers written.
        $repaired = $reconciler->repair($report);

        // After the write, not only before it. `--fix` is a hand-run,
        // money-affecting UPDATE whose only other record is stdout, which is
        // kept nowhere — and "why did our 50-slot offer serve 61 on the 12th"
        // is asked weeks later, by someone who was not at the terminal.
        // `repaired` can trail `reported` legitimately: an offer whose codes the
        // expiry sweep retired between the read and the write no longer drifts.
        Log::warning('offer.quota_counter_repaired', [
            'source' => 'reconcile', // see OfferQuotaCounter::release()
            'repaired' => $repaired,
            'reported' => count($report->drifting),
            'checked' => $report->checked,
            'sample' => $sample,
        ]);

        $this->info(sprintf('Repaired %d of the %d offer(s) reported above.', $repaired, count($report->drifting)));
    }

    /**
     * Count slots held by codes that already lapsed.
     *
     * The reconciler defines "holds a slot" purely by status, with no reference
     * to the clock — so an `issued` code whose `expires_at` has passed still
     * counts, in the counter, in `Offer::hasTotalQuotaLeft()` and in the
     * reconciler's own aggregate. The slot only comes back when
     * `reelmap:redemptions:expire` flips the row. That makes the hourly sweep
     * load-bearing for the cap, and a monitor defined off the same status list
     * is structurally incapable of noticing the sweep stopping.
     *
     * What it looks like when it does: 50 abandoned codes against a 50-slot
     * offer read as `remaining_quota: 0`, the venue loses its map badge and gets
     * zero covers from a promotion it is paying for — while this command prints
     * "Offer quotas healthy" every night and `--fix` recomputes the same wrong
     * number. `Redemption::overdue()` already finds exactly these rows.
     *
     * @return bool whether the expiry sweep is keeping up
     */
    private function auditLapsedCodes(): bool
    {
        // One pass for both numbers: `stalled` is a subset of `held`, so a
        // second `overdue()` count would re-walk the same index — and would do
        // it a moment later, against a different `now()`.
        $lapsed = Redemption::query()
            ->overdue()
            ->toBase()
            ->selectRaw(
                'count(*) AS held, count(*) FILTER (WHERE expires_at <= ?) AS stalled',
                [now()->subHours(self::SWEEP_STALL_HOURS)],
            )
            ->first();

        $held = (int) ($lapsed->held ?? 0);
        $stalled = (int) ($lapsed->stalled ?? 0);

        if ($held === 0) {
            return true;
        }

        if ($stalled === 0) {
            $this->line(sprintf(
                '%d issued code(s) lapsed since the last sweep and still hold a slot — expected; reelmap:redemptions:expire returns them within the hour.',
                $held,
            ));

            return true;
        }

        Log::warning('offer.quota_slots_held_by_lapsed_codes', [
            'source' => 'reconcile',
            'held' => $held,
            'stalled' => $stalled,
            'stall_hours' => self::SWEEP_STALL_HOURS,
        ]);

        $this->error(sprintf(
            'OFFER QUOTA HELD BY LAPSED CODES — %d issued code(s) are past expires_at and still hold a slot, %d of them by over %dh.',
            $held,
            $stalled,
            self::SWEEP_STALL_HOURS,
        ));
        $this->line('  reelmap:redemptions:expire has stopped returning slots. `--fix` cannot repair this — the counter matches the rows; the rows are the problem.');

        return false;
    }

    /**
     * @param  list<array{offer_id: int, counter: int, actual: int}>  $sample
     */
    private function listDrift(array $sample, int $omitted): void
    {
        foreach ($sample as $row) {
            $this->line("  offer {$row['offer_id']}: counter {$row['counter']}, rows say {$row['actual']}");
        }

        // Capped for the same reason the log line is: the scheduler captures
        // this output, and a regressed writer would make it 40,000 lines long.
        if ($omitted > 0) {
            $this->line("  … {$omitted} more.");
        }
    }
}
