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
 * The rows are DERIVED, never authored: {@see App\Models\Concerns\MaterializesDishes}
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
    protected $fillable = ['place_source_id', 'name', 'name_normalized', 'price', 'shown_in_video'];

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
     * the needle into a LIKE pattern without escaping it.
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
