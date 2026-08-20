<?php

use App\Enums\RedemptionStatus;
use App\Services\Redemptions\OfferQuotaCounter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make `offers.redemptions_count` true, and keep it from going below zero (T-127).
 *
 * The column shipped with T-042 as a counter cache over the redemptions that
 * hold a slot ({@see RedemptionStatus::holdingQuota()}) and T-043 never wrote
 * it, so every row in every environment reads whatever the default left there.
 * The application fix ({@see OfferQuotaCounter}) only
 * maintains the counter from here on; existing rows need this one recompute or
 * an offer that has already been redeemed against would start counting from the
 * wrong number.
 *
 * ## Why the floor and not a cap
 *
 * The obvious constraint is `redemptions_count <= quota_total`, which would make
 * overshoot impossible at the database level. It is deliberately NOT added:
 * `OfferController::update()` lets an operator lower `quota_total` at any time,
 * and under such a constraint lowering it below what has already been issued
 * would abort the UPDATE — turning a legitimate edit ("stop at what we have
 * given out") into a 500. Overshoot on the ISSUE path is already impossible
 * without a constraint, because {@see OfferQuotaCounter::claim()}
 * carries the cap in its own WHERE clause and refuses when it cannot win.
 *
 * A negative counter has no such legitimate cause: it can only mean a release
 * ran against a counter that was already wrong, which is corruption rather than
 * an edit. So the floor is enforced, the cap is not.
 *
 * ## Why the transactions are split by hand
 *
 * `$withinTransaction = false` and two explicit transactions, rather than the
 * one Laravel would wrap this in — because that one deadlocks against live
 * issue traffic, reproduced rather than theorised:
 *
 *     ERROR: deadlock detected
 *     Process A waits for AccessExclusiveLock on relation offers
 *     Process B waits for ShareLock on transaction ...
 *
 * In a single transaction the backfill's row locks are still held when
 * `ADD CONSTRAINT` asks for ACCESS EXCLUSIVE, while `RedemptionIssuer`'s
 * `SELECT ... FOR UPDATE` holds ROW SHARE and blocks on a row the backfill
 * touched. Neither can proceed. The migration loses, so the whole backfill rolls
 * back and the deploy lands with the counter still unmaintained — the exact
 * state this exists to end. Separating them means the ALTER runs holding no row
 * locks, so the cycle cannot form.
 *
 * Two things that look like the fix and are not: `SHARE ROW EXCLUSIVE MODE`
 * does not conflict with ROW SHARE, so `SELECT ... FOR UPDATE` walks straight
 * past it and the same deadlock reproduces (EXCLUSIVE is the weakest mode that
 * works, and it still permits plain `SELECT`); and `NOT VALID` +
 * `VALIDATE CONSTRAINT` buys nothing here — `offers` is bounded by the number of
 * promotions so the validation scan is sub-millisecond, and the cycle forms
 * while WAITING for the lock, not while holding it.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $holding = $this->holdingQuotaList();

        DB::transaction(function () use ($holding): void {
            // Taken BEFORE the recompute, for the reason told in full on the row
            // lock in {@see OfferQuotaReconciler::repair()}: under READ
            // COMMITTED, EvalPlanQual resolves a row a concurrent claim commits
            // on mid-statement against the STALE aggregate, writing the
            // pre-claim number back over a code somebody already holds. The
            // whole TABLE rather than that method's row locks, because this
            // recomputes EVERY offer — there is no drift set to scope it to.
            DB::statement('LOCK TABLE offers IN EXCLUSIVE MODE');

            // One statement, correct in both directions: an offer with
            // unrecorded redemptions is raised, and an offer whose counter was
            // set by hand with no rows behind it is dropped back to zero.
            //
            // `COUNT(r.offer_id)`, not `COUNT(r.id)` — same semantics, one index
            // scan instead of a seq scan; measured in
            // {@see OfferQuotaReconciler::heldPerOffer()}.
            DB::statement(<<<SQL
                UPDATE offers
                   SET redemptions_count = recomputed.held,
                       updated_at = offers.updated_at
                  FROM (
                        SELECT o.id, COUNT(r.offer_id) AS held
                          FROM offers o
                          LEFT JOIN redemptions r
                            ON r.offer_id = o.id
                           AND r.status IN ({$holding})
                         GROUP BY o.id
                       ) AS recomputed
                 WHERE offers.id = recomputed.id
                   AND offers.redemptions_count <> recomputed.held
                SQL);
        });

        // Fresh transaction, holding nothing from the backfill.
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_redemptions_count_nonneg_check');
            DB::statement(
                'ALTER TABLE offers ADD CONSTRAINT offers_redemptions_count_nonneg_check
                 CHECK (redemptions_count >= 0)',
            );
        });
    }

    public function down(): void
    {
        // The backfill is NOT reversed. It replaced a number that was wrong with
        // one computed from the rows; restoring the wrong one would be the only
        // way this migration could cost a venue money.
        //
        // One statement, so `$withinTransaction = false` costs it nothing: a
        // lone DDL is its own implicit transaction either way.
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_redemptions_count_nonneg_check');
    }

    /** Inlined rather than bound: the list is variable-length, and every value is an enum case. */
    private function holdingQuotaList(): string
    {
        return collect(RedemptionStatus::holdingQuota())
            ->map(fn (RedemptionStatus $status) => "'{$status->value}'")
            ->implode(', ');
    }
};
