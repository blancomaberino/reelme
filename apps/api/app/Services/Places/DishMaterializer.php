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
 * {@see App\Models\Concerns\MaterializesDishes}, a model hook on `PlaceSource`,
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
    private const MAX_DISHES_PER_SOURCE = 32;

    public function materialize(PlaceSource $source): void
    {
        $rows = $this->rows($source);

        DB::transaction(function () use ($source, $rows): void {
            Dish::query()->where('place_source_id', $source->id)->delete();

            if ($rows !== []) {
                Dish::query()->insert($rows);
            }
        });
    }

    /**
     * The rows a snapshot's `dishes[]` becomes: trimmed, capped, deduped by the
     * EXACT name (first occurrence wins), in snapshot order.
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
        $snapshot = $source->extraction_snapshot_json;
        if (! is_array($snapshot['dishes'] ?? null)) {
            return [];
        }

        $now = now();
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($snapshot['dishes'] as $dish) {
            if (! is_array($dish)) {
                continue;
            }

            $name = mb_substr(trim((string) ($dish['name'] ?? '')), 0, Dish::MAX_NAME);
            if ($name === '' || isset($rows[$name])) {
                continue;
            }

            $price = $dish['price'] ?? null;
            $price = is_string($price) && trim($price) !== ''
                ? mb_substr(trim($price), 0, Dish::MAX_PRICE)
                : null;

            $rows[$name] = [
                'place_source_id' => $source->id,
                'name' => $name,
                // May be '' for a name that is entirely punctuation or emoji.
                // The row is still kept: it is a dish the place detail lists, and
                // dropping it here would make this table disagree with the
                // snapshot it projects. An empty needle never reaches the filter,
                // so such a row simply matches nothing.
                'name_normalized' => Dish::normalizeName($name),
                'price' => $price,
                'shown_in_video' => (bool) ($dish['shown_in_video'] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= self::MAX_DISHES_PER_SOURCE) {
                break;
            }
        }

        return array_values($rows);
    }
}
