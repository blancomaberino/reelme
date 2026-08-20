<?php

namespace App\Services\Redemptions;

use App\Console\Commands\ReconcileOfferQuotas;
use App\Enums\RedemptionStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The safety net under the counter cache (T-127, 06 §2.2).
 *
 * {@see OfferQuotaCounter} keeps `offers.redemptions_count` true going forward,
 * but a counter cache is a second copy of a fact, and a second copy drifts: a
 * hand-written UPDATE, a restore from a partial dump, a future write path that
 * inserts a redemption without going through the issuer. The redemption ROWS are
 * the source of truth — this recomputes from them and says where the two
 * disagree. Nothing else in the system would ever notice.
 *
 * Both halves are ONE statement each, over the whole table. An offer-at-a-time
 * loop would be the same bug in a different place: 40,000 round trips means the
 * check gets moved off the schedule the first time it takes an hour, and a
 * reconciliation nobody runs is worse than none, because it is believed.
 *
 * Report and repair are built from the SAME {@see driftingOffers()} query, not
 * two aggregates that happen to agree today. Two spellings of one rule diverge,
 * and the first symptom would be `--fix` quietly skipping offers the run had
 * just listed — with the command's own "Repaired N of the N reported above"
 * insisting otherwise.
 */
class OfferQuotaReconciler
{
    /**
     * Recompute every offer's true count and report the ones that disagree.
     *
     * Read-only. {@see ReconcileOfferQuotas} is what decides whether to act on
     * the answer.
     */
    public function reconcile(): QuotaDriftReport
    {
        $drifting = $this->driftingOffers()
            ->select('offers.id', 'offers.redemptions_count', 'recomputed.held')
            ->orderBy('offers.id')
            ->get()
            ->map(fn (object $row): array => [
                'offer_id' => (int) $row->id,
                'counter' => (int) $row->redemptions_count,
                'actual' => (int) $row->held,
            ])
            ->all();

        return new QuotaDriftReport(
            checked: DB::table('offers')->count(),
            drifting: $drifting,
        );
    }

    /**
     * Write the recomputed value back over the offers a report named.
     *
     * Takes the report rather than re-deriving its own drift set, so the list
     * the operator was shown and the rows this touches are the same rows. Two
     * independent aggregates would let "Repaired 3 offer(s)" sit under a list of
     * five, and the operator would have no way to tell which three.
     *
     * @return int how many offers were corrected
     */
    public function repair(QuotaDriftReport $report): int
    {
        $ids = array_column($report->drifting, 'offer_id');

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            // Lock the rows we are about to rewrite BEFORE recomputing them,
            // the same `SELECT ... FOR UPDATE` the issuer already holds for the
            // length of its transaction.
            //
            // Not defensive tidiness — without it this statement loses claims
            // that committed while it ran, which is one free redemption past
            // `quota_total` each. In READ COMMITTED the `recomputed` subquery is
            // materialised under the snapshot the UPDATE started with. When the
            // UPDATE reaches a row a concurrent transaction has since committed,
            // it waits, then runs EvalPlanQual — which substitutes only the
            // TARGET relation's new tuple and replays the stale subplan output.
            // So the qual is re-checked with the new counter against the STALE
            // `held`, and writes the stale `held` over the winning claim. Adding
            // a WHERE cannot fix that; only holding the row first can, because
            // READ COMMITTED opens a fresh snapshot per statement — an UPDATE
            // issued once the locks are held sees every claim that committed
            // while we waited, and none can commit behind it.
            //
            // Ordered by id so two concurrent repairs queue instead of
            // deadlocking, and scoped to the drifting rows rather than the whole
            // table so a repair never freezes issuance across every offer.
            DB::table('offers')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            // `updateFrom()`, not `update()`. Both take the same builder, but
            // only this one compiles to Postgres' `UPDATE offers SET ... FROM
            // (recomputed) WHERE ...`; plain `update()` on a joined builder
            // compiles to `WHERE ctid IN (SELECT ...)`, which pushes the join
            // inside the subquery and leaves `recomputed.held` out of scope in
            // the SET clause. Reusing the report's own query as the write's
            // target set is what keeps the two from drifting apart.
            //
            // `updated_at = offers.updated_at` pins the timestamp to its old
            // value, the same way the T-127 backfill migration does. Repairing a
            // counter the offer's owner never touched must not make their offer
            // sort to the top of "recently edited" or invalidate a cache keyed
            // on the edit — the row's CONTENT did not change, only our
            // bookkeeping about it did.
            return $this->driftingOffers($ids)->updateFrom([
                'redemptions_count' => DB::raw('recomputed.held'),
                'updated_at' => DB::raw('offers.updated_at'),
            ]);
        });
    }

    /**
     * The offers whose counter disagrees with their rows, with both numbers.
     *
     * ONE spelling of "drifting", consumed by the report and by the repair.
     *
     * `<>` catches drift in both directions in one pass. A `<` check would only
     * ever find offers that can be over-redeemed and would leave the
     * sold-out-but-not-really ones invisible forever.
     *
     * @param  list<int>  $ids  narrow to these offers; empty means every offer
     */
    private function driftingOffers(array $ids = []): Builder
    {
        return DB::table('offers')
            ->joinSub($this->heldPerOffer($ids), 'recomputed', 'offers.id', '=', 'recomputed.id')
            ->whereColumn('offers.redemptions_count', '<>', 'recomputed.held');
    }

    /**
     * How many slots each offer's redemption rows actually hold.
     *
     * `count(redemptions.offer_id)`, not `count(redemptions.id)`: `offer_id` is
     * covered by `redemptions_offer_id_status_index` and stays an Index Only
     * Scan, while `id` lives only in the heap and forces a seq scan (measured at
     * 1M redemptions: 974 buffers vs 29,499). Identical semantics — `offer_id`
     * is NOT NULL on every matched row, so the LEFT JOIN's NULL-extended rows
     * still count 0.
     *
     * @param  list<int>  $ids  narrow to these offers; empty means every offer
     */
    private function heldPerOffer(array $ids): Builder
    {
        return DB::table('offers')
            // LEFT, not INNER: an offer with no redemption rows at all is
            // precisely the case a counter set by hand or by a bad backfill
            // hides in, and an inner join would silently skip it.
            ->leftJoin('redemptions', fn (JoinClause $join) => $join
                ->on('redemptions.offer_id', '=', 'offers.id')
                // On the JOIN rather than in a WHERE, for the same reason: a
                // WHERE on the right-hand table turns the outer join back into
                // an inner one and drops the zero-row offers.
                ->whereIn('redemptions.status', RedemptionStatus::holdingQuota()))
            ->when($ids !== [], fn (Builder $query) => $query->whereIn('offers.id', $ids))
            ->groupBy('offers.id')
            ->select('offers.id')
            ->selectRaw('count(redemptions.offer_id) AS held');
    }
}
