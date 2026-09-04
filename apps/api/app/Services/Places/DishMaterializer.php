<?php

namespace App\Services\Places;

use App\Models\Dish;
use App\Models\PlaceSource;
use Illuminate\Support\Facades\DB;

/**
 * Projects a source's frozen `dishes[]` into first-class {@see Dish} rows
 * (T-157) — the ONLY writer of the `dishes` table.
 *
 * It is not called from the publish path. It is called from
 * {@see App\Observers\PlaceSourceObserver}, a model observer on `PlaceSource`,
 * so that EVERY way a snapshot comes to exist or change is covered by
 * construction rather than by remembering: the resolver's `firstOrCreate`, the
 * pending-venue resolve, PublishShare's corrected-snapshot overwrite, a Filament
 * edit, a factory in a test. Hanging it off `PlacePublisher::recompute()` — the
 * seam 08 §3.2 suggests — would have covered the publish path only, and left the
 * corpus silently short of every source that never took that route.
 *
 * Writes are a REPLACE of the source's whole dish set, which is what makes the
 * backfill idempotent: running it twice produces the same rows, not double.
 */
class DishMaterializer
{
    /**
     * Hard cap per source — the same defense-in-depth as
     * {@see TagMaterializer::MAX_LABELS_PER_KIND}. The extraction schema already
     * says `maxItems: 32`, but a snapshot is model output that has also passed
     * through a human reviewer's edits, and this table is queried on a public
     * route: the bound belongs on the write path too, not only in the contract.
     */
    public const MAX_DISHES_PER_SOURCE = 32;

    /**
     * @param  bool  $isInsert  the source was just INSERTed, so nothing can already
     *                          own its dish rows and no other session has seen its
     *                          id. Passed by {@see App\Observers\PlaceSourceObserver},
     *                          which has already decided this — recomputing it here
     *                          from `wasRecentlyCreated` is wrong, because that flag
     *                          stays true for the model instance's whole lifetime,
     *                          so emptying a snapshot on a just-created instance
     *                          would skip the DELETE and strand the old rows.
     */
    public function materialize(PlaceSource $source, bool $isInsert = false): void
    {
        $rows = $this->rows($source);

        if ($isInsert) {
            if ($rows === []) {
                return;
            }

            // Still a transaction, but no lock and no DELETE: a fresh bigserial
            // id cannot already own rows and no other session can see it, so the
            // only thing this buys is the SAVEPOINT — which is what lets the
            // observer swallow a failure without poisoning its caller's
            // transaction. That is not optional: a duplicate-key error aborts
            // the surrounding transaction on Postgres (25P02), and a swallowed
            // 25P02 fails every later statement with the cause hidden.
            DB::transaction(fn () => Dish::query()->insert($rows));

            return;
        }

        DB::transaction(function () use ($source, $rows): void {
            // Serialize concurrent materializations of the SAME source. Without
            // this, two delete-then-insert passes under READ COMMITTED both find
            // nothing to delete and both insert, and the loser hits
            // `dishes_place_source_id_name_unique` — failing whatever write is
            // hosting the hook, which is a user's publish or a backfill running
            // against live traffic.
            //
            // Through the query builder, not Eloquent: the lock is all we want,
            // and hydrating the model drags its jsonb snapshot across the wire
            // and through json_decode to be discarded (measured: 0.86ms → 0.52ms).
            DB::table('place_sources')->where('id', $source->id)->lockForUpdate()->value('id');

            Dish::query()->where('place_source_id', $source->id)->delete();

            if ($rows !== []) {
                Dish::query()->insert($rows);
            }
        });
    }

    /**
     * The rows a snapshot's `dishes[]` becomes — {@see parse()} decorated with the
     * owning source and timestamps for a bulk `insert()`.
     *
     * The dedupe rule is not incidental — it is the one
     * {@see PlaceAggregations::tags()} applied when it read the JSON directly,
     * and keeping it identical is what lets that method switch to reading these
     * rows without changing a single response.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(PlaceSource $source): array
    {
        $now = now();

        return array_map(fn (array $dish): array => [
            'place_source_id' => $source->id,
            'name' => $dish['name'],
            'name_normalized' => $dish['name_normalized'],
            'price' => $dish['price'],
            'shown_in_video' => $dish['shown_in_video'],
            'created_at' => $now,
            'updated_at' => $now,
        ], self::parse($source->extraction_snapshot_json));
    }

    /**
     * THE parser for a snapshot's `dishes[]`, and deliberately the only one.
     *
     * Trimmed, capped, deduped by the EXACT name (first occurrence wins), in
     * snapshot order. The dedupe rule is not incidental — it is the one
     * {@see PlaceAggregations::tags()} applied when it read the JSON directly,
     * and keeping it identical is what let that method switch to reading these
     * rows without changing a single response.
     *
     * It is public and static because {@see TagMaterializer} needs the same
     * answer. This field used to have four readers, each with its own parse;
     * three were collapsed into this table, and the fourth (dish TAGS) was left
     * standing with subtly different rules — no `is_string` guard, dedupe before
     * truncation, a different cap — so one hand-edited snapshot could produce a
     * dish row and a dish tag with different text. One parser, one answer.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array{name: string, name_normalized: string, price: string|null, shown_in_video: bool}>
     */
    public static function parse(array $snapshot): array
    {
        if (! is_array($snapshot['dishes'] ?? null)) {
            return [];
        }

        /** @var array<string, array{name: string, name_normalized: string, price: string|null, shown_in_video: bool}> $parsed */
        $parsed = [];

        foreach ($snapshot['dishes'] as $dish) {
            if (! is_array($dish)) {
                continue;
            }

            // `is_scalar`, not `is_string`, and checked BEFORE the cast.
            //
            // Before is the point: a snapshot hand-edited in Filament or tinker
            // is not schema-validated, and casting an array to string throws —
            // which on the WRITE path fails the publish that produced the
            // snapshot, where the same shape used to only spoil one GET.
            //
            // `is_scalar` rather than `is_string` because the three parsers this
            // replaced all did `(string) ($dish['name'] ?? '')`, so a JSON number
            // rendered as the dish "1955". Tightening to `is_string` would have
            // silently dropped it from the place detail, the sources embed AND
            // its dish tag — undocumented data loss dressed as a type guard.
            // Scalars stringify safely; arrays and objects are what throw.
            $raw = $dish['name'] ?? null;
            if (! is_scalar($raw)) {
                continue;
            }

            // Truncation happens BEFORE the dedupe key is taken, deliberately:
            // two names differing only past character 120 then collapse to one
            // row instead of colliding on `unique(place_source_id, name)` and
            // failing the publish. The collapse loses a dish the raw snapshot
            // distinguishes — a real, accepted trade, and only reachable from a
            // hand-corrected snapshot, since the extraction contract caps a name
            // at 120 itself.
            $name = mb_substr(trim((string) $raw), 0, Dish::MAX_NAME);
            if ($name === '' || isset($parsed[$name])) {
                continue;
            }

            $price = $dish['price'] ?? null;
            $price = is_string($price) && trim($price) !== ''
                ? mb_substr(trim($price), 0, Dish::MAX_PRICE)
                : null;

            $parsed[$name] = [
                'name' => $name,
                // May be '' — for punctuation or emoji, and (the larger
                // category) for any script `Str::ascii()` cannot transliterate:
                // Cyrillic, Greek and Arabic do fold, CJK does not. The row is
                // still kept: it is a dish the place detail lists, and dropping
                // it would make this table disagree with the snapshot it
                // projects. It is simply unsearchable — see the KNOWN LIMIT on
                // {@see Dish::normalizeName()}.
                'name_normalized' => Dish::normalizeName($name),
                'price' => $price,
                'shown_in_video' => (bool) ($dish['shown_in_video'] ?? false),
            ];

            if (count($parsed) >= self::MAX_DISHES_PER_SOURCE) {
                break;
            }
        }

        return array_values($parsed);
    }
}
