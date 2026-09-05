<?php

use App\Support\OpeningSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The week's opening spans, projected out of `places.opening_hours_periods_json`
 * so that a LISTING can filter on "open now" (T-158).
 *
 * Why a projection at all, when {@see OpeningSchedule::stateAt()} already
 * answers this: it answers it in PHP, for one place at a time. "Tonight" pages
 * a virtualized list, and a filter applied after the page is cut is not a
 * filter — it silently shortens every page and breaks the cursor. So the
 * predicate has to run in SQL.
 *
 * The alternative was to express the schedule directly over the jsonb. That is
 * a SECOND implementation of the midnight wrap, the week wrap and the two ways
 * a venue says it never closes — and it is the copy nobody would think to test
 * against the other, so the two would diverge on the first edge case. Instead
 * {@see OpeningSchedule::intervals()} was extracted from `stateAt()`, and both
 * the cue and these rows now come from it. The only thing SQL computes is what
 * minute of the local week it is, which is arithmetic, not schedule logic.
 *
 * Shape: half-open `[open_minute, close_minute)` in minutes from Sunday 00:00
 * LOCAL time. `close_minute` MAY EXCEED a week — that is how a span crossing
 * midnight or the week boundary stays a forward interval — so a reader must
 * test the instant in both the current week and the next. Sunday's numbering is
 * Google's day 0, which is also what Postgres `EXTRACT(DOW …)` returns, so no
 * call site has to remember a translation.
 *
 * The zone is COPIED onto every row rather than read from `places` at query
 * time, and that denormalization is a safety property, not a shortcut. Postgres
 * `AT TIME ZONE` THROWS on a value that is not a zone id, so pointing it at a
 * free-text column means one junk `places.timezone` turns every listing into a
 * 500. These rows are written only by {@see OpenPeriodMaterializer},
 * which writes nothing unless PHP *and* Postgres both accept the id — so the
 * column the query dereferences is one that has been proven to be a zone. The
 * usual cost of a copy (drift) is paid off by the observer: the rows are
 * rewritten whenever the periods OR the timezone change, and a place whose zone
 * became unusable loses its rows, which is the same "no cue" answer
 * {@see OpeningSchedule::stateAt()} gives.
 *
 * No backfill in this migration — {@see BackfillOpenPeriods}
 * does it, and `scripts/deploy.sh` runs it inside the maintenance window. That
 * is the shape T-157 arrived at after putting a backfill in a migration and
 * having to take it back out: schema history must not depend on a mutable
 * application service, and an O(corpus) serial walk with no progress and no
 * resume does not belong in an outage.
 *
 * A place with no periods, or no timezone, gets NO ROWS — so an open-now filter
 * is an inner semi-join and unknown hours are excluded by construction. That is
 * the T-128/T-155 rule ("say closed from the ABSENCE of a period, never from a
 * guess") enforced by the shape of the data instead of by a convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_open_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();

            // smallint: a week is 10080 minutes and a wrapped close reaches at
            // most 20159, both inside smallint's 32767. The narrow type is not
            // for space — it is a bound. A value that does not fit is not a
            // clock, and failing the write is better than storing a span that
            // matches every instant.
            $table->smallInteger('open_minute');
            $table->smallInteger('close_minute');

            // Validated at write time; see the note above on why this is a copy.
            $table->string('timezone', 64);

            // The uniqueness is what makes the REPLACE idempotent AND catches a
            // duplicate the materializer failed to fold: running the backfill
            // twice must produce the same rows, not double.
            //
            // It doubles as the read index: every query enters by `place_id`,
            // which is this key's leading column. A place holds at most
            // MAX_PERIODS (14) rows, so nothing past that prefix has work to do.
            // `timezone` is deliberately NOT part of the key: it is the same
            // for every row of a place, so including it would widen the index
            // without distinguishing anything.
            $table->unique(['place_id', 'open_minute', 'close_minute']);
        });

        // The invariant the whole read predicate rests on, enforced rather than
        // assumed. `intervals()` can only produce a span of 1..10080 minutes —
        // it wraps a close that is at or before its open by exactly one week —
        // and the containment test `((now - open + 10080) %% 10080) < close - open`
        // is only equivalent to "is now inside this span" while that holds. A
        // row with a longer span matches EVERY instant, so one bad insert puts a
        // closed venue in every "open now" listing forever. The smallint type
        // bounds these columns at 32767, which is not the same statement.
        DB::statement(
            'ALTER TABLE place_open_periods ADD CONSTRAINT place_open_periods_span_check '
            .'CHECK (close_minute > open_minute AND close_minute - open_minute <= 10080)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('place_open_periods');
    }
};
