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
     * Whether Postgres can resolve a zone id, by id — BOTH answers cached.
     *
     * Caching the failures is safe here, and only because of what runs first:
     * a key reaches this map only after {@see OpeningSchedule::zoneId()} has
     * accepted it, which requires a constructible REGION/CITY id, so the key
     * space is bounded by PHP's tz database (~600 ids) rather than by whatever
     * junk the free-text `places.timezone` column happens to hold. Without that
     * gate in front, negative caching here WOULD be unbounded growth in a
     * long-lived Horizon or Octane process.
     *
     * @var array<string, bool>
     */
    private static array $zones = [];

    /**
     * @param  bool  $isInsert  the place was just INSERTed, so nothing can already
     *                          own its rows and no other session has seen its id.
     *                          Decided by the observer — see the note on
     *                          {@see DishMaterializer::materialize()} for why
     *                          recomputing it here from `wasRecentlyCreated`
     *                          would be wrong.
     */
    public function materialize(Place $place, bool $isInsert = false): void
    {
        if ($isInsert) {
            $rows = $this->rows($place);

            if ($rows === []) {
                return;
            }

            // Still a transaction, but no lock and no DELETE: a fresh id cannot
            // already own rows and no other session can see it. The SAVEPOINT is
            // the point — it is what lets the observer swallow a failure without
            // poisoning its caller's transaction, since on Postgres an error
            // aborts the surrounding transaction (25P02) and a swallowed 25P02
            // fails every later statement with the cause hidden.
            DB::transaction(fn () => PlaceOpenPeriod::query()->insert($rows));

            return;
        }

        DB::transaction(function () use ($place): void {
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
                return;
            }

            $rows = $this->rows($locked);

            PlaceOpenPeriod::query()->where('place_id', $locked->id)->delete();

            if ($rows !== []) {
                PlaceOpenPeriod::query()->insert($rows);
            }
        });
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

        if (! isset(self::$zones[$timezone])) {
            self::$zones[$timezone] = DB::table('pg_timezone_names')->where('name', $timezone)->exists();
        }

        return self::$zones[$timezone] ? $timezone : null;
    }
}
