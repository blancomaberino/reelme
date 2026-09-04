<?php

use App\Models\Dish;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Services\Places\DishMaterializer;
use App\Services\Places\PlaceMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dishes become a first-class, queryable thing (T-157, 08 §3.2).
 *
 * The pipeline has been extracting dish names — and, since the menu-photo work,
 * their prices — for every published post, and burying them in
 * `place_sources.extraction_snapshot_json`, where nothing can ask a question of
 * them. `?dish=pasta` ("five places near me that do pasta") cannot be answered
 * by `cuisine_primary` (which says `italian`) nor by the vibe chips. This table
 * is that answer.
 *
 * Two deliberate deviations from the shape 08 §3.2 sketches
 * (`dishes(place_id, name, price, currency, shown_in_video, language, source_id)`),
 * both recorded in ADR-157:
 *
 * 1. **No `place_id`.** A dish belongs to the SOURCE that claimed it, and the
 *    source already carries the place. Storing the place again would create a
 *    second answer to "which place is this dish on", which every path that moves
 *    a source between places would then have to remember to update —
 *    {@see PlaceMerger} rehomes `place_sources.place_id` and
 *    reverses it on unmerge, and a forgotten dish rehome there is a silent
 *    corpus corruption no test in the merge suite would notice. Reading the
 *    place through the source is one join, and it cannot drift.
 * 2. **No `currency`, no `language`.** `price` is stored EXACTLY as the source
 *    wrote it ("$450", "12€", "UYU 320") — that is what the extraction contract
 *    defines and all the client renders. Splitting an amount and a currency out
 *    is price-aware ranking's problem (08 §1.2), and inventing the split here,
 *    unused, would mean guessing a currency from a symbol on the write path.
 *    `language` likewise: {@see Place::dishesLanguage()} already
 *    answers it from the source. Neither column has a reader today.
 *
 * pgvector/embeddings are explicitly NOT here (T-157's brief): the plain table
 * answers "who does pasta"; semantic search is a later task built on top of it.
 *
 * `name_normalized` is the query column — accent-folded, lowercased, punctuation
 * flattened by {@see Dish::normalizeName()} — so `?dish=ñoquis`,
 * `?dish=Noquis` and `?dish=ÑOQUIS` are one query. The trigram GIN index below
 * is what keeps the substring match off a sequential scan; it mirrors
 * `places_normalized_name_trgm`, which does the same job for place names.
 *
 * The unique key is `(place_source_id, name)` — the EXACT name, not the
 * normalized one. Deduping on the normalized form would collapse two dishes a
 * menu genuinely distinguishes, and would quietly change what the place detail
 * lists; the aggregation this table replaces dedupes by exact name, and this
 * key is what keeps the two identical.
 */
return new class extends Migration
{
    /**
     * Opted OUT of the migration-wide transaction, because of the backfill at
     * the end of `up()`: Laravel wraps a whole migration in one transaction on
     * Postgres, and `DishMaterializer::materialize()` opens its own — so the
     * corpus would run as one SAVEPOINT per source. Past ~64 subtransactions in
     * a single transaction Postgres spills to the `pg_subtrans` SLRU and every
     * concurrent backend pays for it on visibility checks, and the whole run
     * would sit under one long-lived xmin blocking autovacuum.
     *
     * Losing atomicity is safe here precisely because the projection is a
     * replace-per-source: a partial run leaves correct rows for the sources it
     * reached, and `reelmap:dishes:backfill` finishes the job.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('dishes', function (Blueprint $table): void {
            $table->id();
            // The claim's owner. Cascade: a source deleted by a reprocess
            // (ForceReprocessShare), a merge's duplicate drop, or a user's own
            // "remove this place" takes its dish claims with it.
            $table->foreignId('place_source_id')->constrained('place_sources')->cascadeOnDelete();
            // Verbatim, in the post's language — never translated. 120 is the
            // extraction contract's own maxLength for a dish name.
            $table->string('name', 120);
            $table->string('name_normalized', 120);
            // Verbatim too, including the currency symbol; null when the source
            // showed no price. 40 matches the contract.
            $table->string('price', 40)->nullable();
            $table->boolean('shown_in_video')->default(false);
            $table->timestampsTz();

            $table->unique(['place_source_id', 'name']);
        });

        DB::statement('CREATE INDEX dishes_name_normalized_trgm ON dishes USING GIN (name_normalized gin_trgm_ops)');

        // The backfill is part of SHIPPING this, not a repair tool — which is why
        // it runs here rather than being left to `reelmap:dishes:backfill`.
        //
        // `PlaceAggregations` and `PlaceSourceResource` read this table from the
        // moment this migration lands. An empty table is therefore not a cold
        // start, it is a live regression: every existing place detail loses its
        // menu (the mobile sheet gates on `dishes.length > 0`) while the payload
        // still reports a `dishes_updated_at`. `scripts/deploy.sh` runs
        // `artisan migrate` inside maintenance mode and nothing else, so a step
        // someone has to remember is a step that happens after the outage.
        //
        // This is the difference from T-031's `reelmap:tags:backfill`, which is
        // also absent from the deploy: tags were ADDITIVE — an unbackfilled place
        // simply lacked a facet it never had — whereas here the read side was
        // switched over in the same commit.
        //
        // Three things make an in-migration backfill safe enough to prefer over
        // a remembered deploy step, and each is load-bearing:
        //
        // 1. `$withinTransaction = false` above. The DDL is already committed by
        //    the time this runs, so a failure here leaves the TABLE and the
        //    INDEX in place and the projection merely partial. That matters more
        //    than it sounds: `scripts/deploy.sh` pulls and installs the new code
        //    BEFORE entering maintenance mode, and its trap runs `artisan up` on
        //    failure — so a migration that rolled the table back would put the
        //    new code in front of users against a schema with no `dishes`,
        //    turning every place detail into a 42P01. Partial beats absent.
        // 2. The per-source `catch`. Maintenance mode stops HTTP, not Horizon:
        //    the queue keeps publishing and force-reprocessing while this walks
        //    the table, so a source can vanish between the chunk's SELECT and
        //    its INSERT. That is a routine race, not a corrupt deploy, and it
        //    must not abort a run that is minutes long.
        // 3. The container lookup is INSIDE the loop. A fresh database — CI, a
        //    new machine, a DR rebuild — has no sources, so nothing is resolved
        //    and this migration keeps replaying years from now even if
        //    DishMaterializer is renamed or deleted. That is the usual objection
        //    to touching app code from a migration, and this is what answers it.
        //
        // It goes through DishMaterializer rather than re-deriving the projection
        // in SQL because `Dish::normalizeName()` is PHP (`Str::ascii()`), and a
        // hand-written SQL twin of it is exactly the second implementation this
        // whole design exists to avoid.
        $failed = [];
        PlaceSource::query()->chunkById(200, function ($chunk) use (&$failed): void {
            foreach ($chunk as $source) {
                try {
                    app(DishMaterializer::class)->materialize($source);
                } catch (Throwable $e) {
                    $failed[] = $source->id;
                    report($e);
                }
            }
        });

        if ($failed !== []) {
            // Never silent: these sources have a snapshot and no rows, so their
            // places show no menu until someone re-runs the command.
            Log::warning('dishes.backfill_incomplete', [
                'place_source_ids' => $failed,
                'repair' => 'php artisan reelmap:dishes:backfill',
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dishes');
    }
};
