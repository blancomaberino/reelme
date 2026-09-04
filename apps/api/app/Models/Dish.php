<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One dish claimed by one source (T-157, 08 §3.2) — the queryable form of what
 * used to live only inside `place_sources.extraction_snapshot_json`.
 *
 * The rows are DERIVED, never authored: {@see App\Observers\PlaceSourceObserver}
 * rewrites a source's whole dish set whenever its snapshot is written, so the
 * table is a projection of the snapshots and can always be rebuilt from them
 * (`php artisan reelmap:dishes:backfill`). Nothing else may write here — a
 * second writer would be a second answer to "what did this post say they serve".
 *
 * The place is reached THROUGH the source (`place_sources.place_id`), which is
 * why there is no `place_id` column: see the migration's note.
 *
 * @property int $id
 * @property int $place_source_id
 * @property string $name
 * @property string $name_normalized
 * @property string|null $price
 * @property bool $shown_in_video
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Dish extends Model
{
    /**
     * Nothing mass-assigns a Dish — {@see App\Services\Places\DishMaterializer} is
     * the only writer and it goes through `insert()`, which bypasses `$fillable`
     * entirely. The list is deliberately EMPTY rather than decorative: a
     * populated one reads as a write-path control that enforces nothing, and it
     * would invite a `Dish::create($input)` that sets `name_normalized`
     * independently of `name` — a row that displays as "Salad" and answers
     * `?dish=pizza`.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The column's own limit, restated so the write path caps the value rather
     * than letting Postgres reject a 121-character dish name mid-publish. Dish
     * text is untrusted model output: it is capped here, stored verbatim, and
     * rendered as data (JSON) — never as markup — everywhere it surfaces.
     */
    public const MAX_NAME = 120;

    /** As above, for the verbatim price string ("$450", "12€", "UYU 320"). */
    public const MAX_PRICE = 40;

    /**
     * The shortest NORMALIZED term `?dish=` will match on, and it is a
     * performance boundary rather than a taste one: pg_trgm extracts no trigram
     * from a wildcard-free segment shorter than three characters, so
     * `LIKE '%ab%'` cannot use `dishes_name_normalized_trgm` at all and degrades
     * to a sequential scan of every dish in the corpus — on a public,
     * unauthenticated route.
     *
     * Measured on a 600k-dish / 60k-place corpus, EXPLAIN (ANALYZE): a 2-char
     * needle seq-scans; `pasta` runs 281 ms and `pastas caseras` 27 ms. Note what
     * those numbers are NOT: an earlier version of this comment quoted "6 ms
     * indexed", which was the bare index probe rather than the query the builder
     * emits. Worth stating precisely, because a floor justified by a number
     * nobody re-measured is a floor nobody will revisit.
     *
     * Also measured, and NOT fixable by an obvious index: with a viewport (the
     * map path) the planner drives from `places` and applies the dish match as a
     * heap filter, so the trigram index goes unused there. That is right while a
     * viewport holds tens of places and wrong when it holds thousands — a real
     * ceiling on this design, and the reason semantic search is a later task
     * rather than a bigger LIKE.
     *
     * It is enforced on the NORMALIZED needle, never the raw input: `?dish=p.`
     * and `?dish=ño` both clear a `min:2` on the raw string and both reduce to
     * fewer than three characters of match text.
     *
     * The floor has one honest Spanish casualty: `té` normalizes to `te`, so a
     * menu line that really exists is unqueryable. Low commercial intent, and a
     * beverage rather than a dish — not worth lowering the floor and giving up
     * the index for, but a known trade rather than an oversight.
     */
    public const MIN_QUERY = 3;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shown_in_video' => 'boolean'];
    }

    /**
     * The match form: accent-folded, lowercased, everything that is not a letter
     * or a digit flattened to a single space. `?dish=Ñoquis`, `?dish=noquis` and
     * `?dish=ÑOQUIS` all reduce to `noquis`, which is what makes the filter case-
     * and accent-insensitive without a functional index or a citext column.
     *
     * Deliberately NOT {@see App\Models\Concerns\DerivesNameColumns::normalizeName()}:
     * that one also strips company/legal suffixes (`sa`, `co`, `srl`, …) because
     * it matches BUSINESS names. A dish is not a business — "Salteñas" would keep
     * its meaning but "Bacalao a la co…" would not, and dropping a real word from
     * a menu item is a silent recall hole.
     *
     * Because the result contains only `[a-z0-9 ]`, a LIKE wildcard (`%`, `_`)
     * cannot survive normalization — which is why the query side can interpolate
     * the needle into a LIKE pattern without escaping it. (The `preg_replace`
     * carries no `/u` on purpose: it makes the pass byte-safe on invalid UTF-8,
     * which would otherwise return null.)
     *
     * KNOWN LIMIT — scripts `Str::ascii()` cannot transliterate normalize to the
     * empty string, and a dish stored that way can never match `?dish=`. Cyrillic,
     * Greek and Arabic DO transliterate ("Борщ" → "borshh"); CJK does not
     * ("とんこつラーメン" → ""). The row is still written — it is a dish the place
     * detail lists — it is simply unsearchable. Irrelevant for a Montevideo
     * corpus and stated here rather than left to be discovered; a multilingual
     * corpus needs a different match column, not a wider regex.
     *
     * Anything building an inventory of dish TERMS off this column (the long-tail
     * SEO surface of 08 §2.1 is the obvious future one) must filter
     * `name_normalized <> ''`, or the empty bucket becomes a page.
     */
    public static function normalizeName(string $name): string
    {
        $value = Str::of($name)->ascii()->lower()->toString();
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return mb_substr(trim($value), 0, self::MAX_NAME);
    }

    /** @return BelongsTo<PlaceSource, $this> */
    public function placeSource(): BelongsTo
    {
        return $this->belongsTo(PlaceSource::class);
    }
}
