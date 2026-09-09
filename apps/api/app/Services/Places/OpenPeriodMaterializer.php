<?php

namespace App\Services\Places;

use App\Models\Place;
use App\Models\PlaceOpenPeriod;
use App\Support\OpeningSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Projects a place's structured hours into {@see PlaceOpenPeriod} rows (T-158)
 * — the ONLY writer of the `place_open_periods` table.
 *
 * It is called from {@see App\Observers\PlaceObserver}, a model observer, for
 * the reason CLAUDE.md's "a new rule needs every writer" gives: the state these
 * rows derive from is two columns on `places`, and at least four things already
 * write them — `BusinessEnricher` through {@see App\Services\Geo\BusinessDetails},
 * {@see PlaceEditor::apply()}, {@see PlaceMerger} donating a loser's hours, and
 * Filament. Hooking whichever one was open in the editor would have covered that
 * path and silently missed the rest.
 *
 * Writes are a REPLACE of the place's whole set, which is what makes the
 * backfill idempotent: running it twice produces the same rows, not double.
 */
class OpenPeriodMaterializer
{
    /**
     * Every zone id THIS Postgres can resolve, loaded once per process.
     *
     * Loaded whole rather than probed per id, because `pg_timezone_names` is a
     * set-returning function over ~1200 zones with no server-side caching:
     * measured on this stack it costs ~150ms per call, EVERY call. Probing one
     * id at a time made that a 150ms tax on the first hours-write in each
     * PHP-FPM process — a Filament edit, a `PlaceEditor::apply()` — for a
     * question whose answer is identical for every id and changes only when the
     * server's tz database is upgraded. One query answers it for all of them at
     * the same price.
     *
     * Reloaded while EMPTY, not merely while null. A Postgres whose system
     * tzdata directory is missing returns zero rows from `pg_timezone_names`
     * without error; latching that would leave the process rejecting every zone
     * for its whole life, where the per-id probe this replaced would have healed
     * once the server was fixed. An empty map is never a correct answer here —
     * Postgres always knows some zones.
     *
     * @var array<string, true>|null
     */
    private static ?array $zones = null;

    /**
     * @param  bool  $isInsert  the place was just INSERTed, so nothing can already
     *                          own its rows and no other session has seen its id.
     *                          Decided by the observer — see the note on
     *                          {@see DishMaterializer::materialize()} for why
     *                          recomputing it here from `wasRecentlyCreated`
     *                          would be wrong.
     * @return bool whether the stored projection actually CHANGED — the signal
     *              the nightly pass reports on, see the note at the comparison
     */
    public function materialize(Place $place, bool $isInsert = false): bool
    {
        if ($isInsert) {
            $rows = $this->rows($place);

            if ($rows === []) {
                return false;
            }

            // Still a transaction, but no lock and no DELETE: a fresh id cannot
            // already own rows and no other session can see it. The SAVEPOINT is
            // the point — it is what lets the observer swallow a failure without
            // poisoning its caller's transaction, since on Postgres an error
            // aborts the surrounding transaction (25P02) and a swallowed 25P02
            // fails every later statement with the cause hidden.
            DB::transaction(fn () => PlaceOpenPeriod::query()->insert($rows));

            return true;
        }

        return (bool) DB::transaction(function () use ($place): bool {
            // Serialize concurrent materializations of the SAME place, and
            // re-read the hours HERE, under the lock, rather than trusting the
            // `$place` we were handed. Locking the WRITE while the READ stayed
            // unprotected is a lock in front of a value nobody re-read: the
            // backfill hydrates a chunk, an enrichment corrects place #7's
            // hours, and the backfill then reaches #7 and reinstates the old
            // week. `withoutGlobalScopes()` because a future global scope on
            // Place (a `published` filter, say) would make this null and turn
            // the whole replace into a silent no-op through the guard below.
            $locked = Place::query()
                ->withoutGlobalScopes()
                ->whereKey($place->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                // Deleted between hydration and the lock. The FK cascade already
                // took its rows; writing now would resurrect them as orphans.
                return false;
            }

            $rows = $this->rows($locked);

            // Compare before rewriting, and RETURN whether anything differed.
            //
            // Two things depend on this, and the second is the important one.
            // The cheap one: in a healthy system the nightly pass finds every
            // place already correct, so an unconditional DELETE + INSERT would
            // rewrite the whole projection every night — a full table's worth of
            // dead tuples and WAL for no change.
            //
            // The one that matters: a repair that reports the same thing whether
            // it fixed nothing or fixed four thousand places HIDES the broken
            // writer it exists to compensate for. `reelmap:offers:reconcile-quotas`
            // already states this rule for the same shape of problem — silently
            // correcting drift every night hides the bug rather than surfacing
            // it. The observer is the writer here; if it is dropping places, the
            // count of differences is the only thing that says so.
            if ($this->matches($locked->id, $rows)) {
                return false;
            }

            PlaceOpenPeriod::query()->where('place_id', $locked->id)->delete();

            if ($rows !== []) {
                PlaceOpenPeriod::query()->insert($rows);
            }

            return true;
        });
    }

    /**
     * Whether the stored rows for a place are already exactly `$rows`.
     *
     * Compared as SETS of the three columns that make a period, because that is
     * what the read predicate uses: `place_id` to enter, `open_minute` and
     * `close_minute` for containment, and `timezone` to place them. Row order is
     * not meaningful — there is no ordering column — and comparing ids would
     * make every row differ from itself, since a rewrite mints new ones.
     *
     * Called under the same lock as the write, so the answer cannot go stale
     * between the check and the DELETE.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function matches(int $placeId, array $rows): bool
    {
        $stored = PlaceOpenPeriod::query()
            ->where('place_id', $placeId)
            ->get(['open_minute', 'close_minute', 'timezone'])
            ->map(fn (PlaceOpenPeriod $p) => [(int) $p->open_minute, (int) $p->close_minute, (string) $p->timezone])
            ->all();

        $wanted = array_map(
            fn (array $r) => [(int) $r['open_minute'], (int) $r['close_minute'], (string) $r['timezone']],
            $rows,
        );

        sort($stored);
        sort($wanted);

        return $stored === $wanted;
    }

    /**
     * The rows a place's hours become — empty when EITHER half is unusable.
     *
     * Both halves are required, and that is the T-128/T-155 rule rather than a
     * defensive habit: periods without a zone place the week nowhere, and a zone
     * without periods describes nothing. Returning no rows means the place is
     * excluded from an open-now filter, which is the same answer the cue gives
     * ("no cue", never "closed").
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Place $place): array
    {
        $timezone = $this->resolvableZone($place->timezone);

        if ($timezone === null) {
            return [];
        }

        $intervals = OpeningSchedule::intervals($place->opening_hours_periods_json);

        if ($intervals === null) {
            return [];
        }

        $rows = [];

        foreach ($intervals as [$open, $close]) {
            // Folded here rather than left to the unique index: two identical
            // periods in one provider payload is a duplicate, not a conflict,
            // and letting it reach the index would fail the enrichment that
            // produced them. The index still stands as the backstop.
            $rows[$open.':'.$close] = [
                'place_id' => $place->id,
                'open_minute' => $open,
                'close_minute' => $close,
                'timezone' => $timezone,
            ];
        }

        return array_values($rows);
    }

    /**
     * The zone id, if BOTH PHP and Postgres can resolve it — otherwise null.
     *
     * Postgres is asked because it is the one that will dereference this value:
     * `AT TIME ZONE` throws on an id it does not know, and the query that throws
     * is a public listing, so a single unresolvable id would be a 500 for
     * everyone rather than a missing cue for one venue. PHP's tz database and
     * the server's are both IANA but are updated independently, so "PHP accepted
     * it" is not the same claim.
     *
     * Asking costs one catalog lookup per distinct id per process, on the WRITE
     * path only.
     */
    private function resolvableZone(?string $timezone): ?string
    {
        // PHP first: it is the cheaper check and it is also the stricter one —
        // it refuses fixed offsets like "+05:00", which are exactly what this
        // column must never hold, and which Postgres would happily accept.
        $timezone = OpeningSchedule::zoneId($timezone);

        if ($timezone === null) {
            return null;
        }

        if (! self::$zones) {
            /** @var array<string, true> $loaded */
            $loaded = [];
            foreach (DB::table('pg_timezone_names')->pluck('name') as $name) {
                $loaded[(string) $name] = true;
            }
            self::$zones = $loaded;
        }

        return isset(self::$zones[$timezone]) ? $timezone : null;
    }
}
