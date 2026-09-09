<?php

namespace App\Support;

/**
 * The retention window the privacy policy publishes, in one place (T-156).
 *
 * The window is stated in prose ("Server logs: 14 days"), enforced on files by
 * `reelmap:logs:prune`, and enforced on `failed_jobs` by `queue:prune-failed`.
 * Three consumers of one promise, and they read it differently: Monolog counts
 * DAYS and treats 0 as keep-forever, artisan's `queue:prune-failed` counts
 * HOURS and treats 0 as delete-everything-before-now.
 *
 * That inversion is the hazard this class exists for. `LOG_DAILY_DAYS=` (empty)
 * casts to 0, which would leave the log files untouched and silently destroy
 * the entire failed-job table — the record an incident is reconstructed from —
 * on the next nightly run, reporting success. A floor on one consumer and not
 * the other is exactly how that ships.
 *
 * So the floor lives here, with the conversion, and callers ask rather than
 * compute. `PruneLogFiles` deliberately does NOT use `days()`: a misconfigured
 * window must make the file sweep fail loudly rather than quietly act on a
 * substituted value, so it reads the raw config and refuses.
 */
final class RetentionWindow
{
    /** Days of request data we keep, never less than one. */
    public static function days(): int
    {
        return max(1, self::configuredDays());
    }

    /** The same window in hours, for consumers that count in hours. */
    public static function hours(): int
    {
        return 24 * self::days();
    }

    /**
     * The raw configured value, floor included — for the caller that needs to
     * REFUSE a bad window rather than substitute a safe one.
     */
    public static function configuredDays(): int
    {
        return (int) config('logging.channels.daily.days');
    }
}
