<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The window an influencer dashboard reports over (T-048, 06 §5.2).
 *
 * Three fixed choices rather than a free `from`/`to` pair: every extra window is
 * another cache key and another aggregate over the same indexes, and "last 30
 * days / last 90 days / everything" is the whole question this dashboard
 * answers. A custom range belongs to an export, not to a headline funnel.
 */
enum DashboardPeriod: string
{
    case Last30Days = '30d';
    case Last90Days = '90d';
    case AllTime = 'all';

    /**
     * The inclusive lower bound, or null for all-time.
     *
     * Derived from `CarbonImmutable::now()` so `Carbon::setTestNow()` pins it —
     * the task's timezone gotcha. Subtracting DAYS (not months) keeps "30d"
     * meaning the same length in every month.
     */
    public function since(): ?CarbonImmutable
    {
        return match ($this) {
            self::Last30Days => CarbonImmutable::now()->subDays(30),
            self::Last90Days => CarbonImmutable::now()->subDays(90),
            self::AllTime => null,
        };
    }

    /** Cache-key fragment; also the value echoed back in the payload. */
    public function key(): string
    {
        return $this->value;
    }
}
