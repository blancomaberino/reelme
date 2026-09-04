<?php

use App\Models\Dish;
use App\Models\Place;
use App\Services\Places\PlaceMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('dishes');
    }
};
