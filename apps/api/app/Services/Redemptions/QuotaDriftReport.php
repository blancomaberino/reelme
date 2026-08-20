<?php

namespace App\Services\Redemptions;

use App\Services\Ledger\InvariantReport;

/**
 * The reconciliation run's verdict (T-127, 06 §2.2).
 *
 * Deliberately shaped like {@see InvariantReport}: one `isHealthy()` a caller
 * can branch on and one `summary()` line an on-call person reads first, because
 * a report that has to be interpreted is a report nobody reads.
 *
 * `checked` counts every offer the aggregate looked at, not just the offenders.
 * "3 offers drifted" says nothing on its own — out of 4 it is a broken write
 * path, out of 40,000 it is a handful of rows from before the counter was
 * maintained at all.
 *
 * Each drift row keeps BOTH numbers, because the direction is the diagnosis:
 * a counter above the rows means an offer is being badged sold out while the
 * restaurant is still paying to run it; below them means it can be redeemed
 * past its own cap.
 */
final readonly class QuotaDriftReport
{
    /**
     * How many drift rows a log line or a console listing may carry.
     *
     * The condition this report exists to catch — a regressed writer — drifts
     * EVERY offer, so the unbounded set is largest exactly when it fires. A
     * multi-megabyte log record is dropped or truncated by the shipper at
     * precisely that moment, and a listing that scrolls for 40,000 lines is not
     * read either. The count carries the severity; the sample carries enough to
     * start on, and `--fix` needs neither.
     */
    public const SAMPLE_SIZE = 20;

    /**
     * @param  list<array{offer_id: int, counter: int, actual: int}>  $drifting
     */
    public function __construct(
        public int $checked,
        public array $drifting,
    ) {}

    public function isHealthy(): bool
    {
        return $this->drifting === [];
    }

    /**
     * The first few drift rows, for anywhere the whole set does not belong.
     *
     * @return list<array{offer_id: int, counter: int, actual: int}>
     */
    public function sample(): array
    {
        return array_slice($this->drifting, 0, self::SAMPLE_SIZE);
    }

    /**
     * How many drift rows {@see sample()} left behind.
     *
     * Owned here rather than re-derived at the console, so the cap and the
     * "… N more." that accounts for it cannot drift apart — which is the same
     * class of bug this whole report exists to find.
     */
    public function omitted(): int
    {
        return count($this->drifting) - count($this->sample());
    }

    /** One line for a log or an alert — the thing an on-call person reads first. */
    public function summary(): string
    {
        if ($this->isHealthy()) {
            return "Offer quotas healthy: {$this->checked} offer(s) agree with their redemption rows.";
        }

        return sprintf(
            'OFFER QUOTA DRIFT — %d of %d offer(s) disagree with their redemption rows.',
            count($this->drifting),
            $this->checked,
        );
    }
}
