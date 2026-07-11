<?php

namespace App\Services\Places;

use App\Enums\TagKind;
use App\Models\Place;
use App\Models\Tag;

/**
 * Materializes first-class tags from a frozen extraction snapshot on publish
 * (T-031, 02 §3.10). Free-text labels are slugified and junk (empty / 1-char)
 * dropped; re-attaching an existing tag keeps the MAX confidence (mirrors the
 * merge rule of 02 §4.3). Also backfills `places.cuisine_primary` from the
 * snapshot's first cuisine when the place has none.
 */
class TagMaterializer
{
    /**
     * Hard cap per kind — defense in depth behind the schema's maxItems: a
     * snapshot is model/reviewer-controlled input and every unique slug is a
     * PERMANENT global tags row, so this must be bounded here too.
     */
    private const MAX_LABELS_PER_KIND = 32;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function materialize(Place $place, array $snapshot, ?float $confidence): void
    {
        $labels = [
            TagKind::Cuisine->value => $this->stringList($snapshot['cuisines'] ?? null),
            TagKind::Vibe->value => $this->stringList($snapshot['vibe_tags'] ?? null),
            TagKind::Diet->value => $this->stringList($snapshot['dietary_tags'] ?? null),
            TagKind::Dish->value => $this->dishNames($snapshot['dishes'] ?? null),
        ];

        $attach = [];
        foreach ($labels as $kind => $names) {
            foreach (array_slice($names, 0, self::MAX_LABELS_PER_KIND) as $name) {
                $slug = Tag::makeSlug($name);
                if ($slug === '') {
                    continue;
                }

                $tag = Tag::query()->firstOrCreate(
                    ['kind' => $kind, 'slug' => $slug],
                    ['name' => mb_substr($name, 0, 80)],
                );
                $attach[$tag->id] = [
                    'source' => 'extraction',
                    'confidence' => $confidence !== null ? round($confidence, 3) : null,
                ];
            }
        }

        if ($attach !== []) {
            // syncWithoutDetaching is race-safe on the (place_id, tag_id) PK —
            // two workers publishing to the same place must not blow up the
            // publish job on a duplicate attach. It also updates the pivot of
            // rows that already exist, so re-apply the max-confidence rule for
            // any existing pivot that had a HIGHER confidence than this run.
            $existing = $place->tags()
                ->whereIn('tags.id', array_keys($attach))
                ->get()
                ->keyBy('id');

            $sync = [];
            foreach ($attach as $tagId => $pivot) {
                $current = $existing->get($tagId)?->getRelationValue('pivot');
                if ($current === null) {
                    $sync[$tagId] = $pivot;

                    continue;
                }
                // Existing pivot: never downgrade confidence, never touch the
                // provenance (a manual/owner tag stays manual/owner).
                $confidence = $pivot['confidence'];
                if ($current->confidence !== null
                    && ($confidence === null || (float) $current->confidence >= (float) $confidence)) {
                    $confidence = (float) $current->confidence;
                }
                $sync[$tagId] = ['confidence' => $confidence];
            }

            $place->tags()->syncWithoutDetaching($sync);
        }

        if ($place->cuisine_primary === null && ($labels[TagKind::Cuisine->value][0] ?? null) !== null) {
            $slug = Tag::makeSlug($labels[TagKind::Cuisine->value][0]);
            if ($slug !== '') {
                $place->cuisine_primary = $slug;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function dishNames(mixed $dishes): array
    {
        if (! is_array($dishes)) {
            return [];
        }

        $names = [];
        foreach ($dishes as $dish) {
            $name = is_array($dish) ? trim((string) ($dish['name'] ?? '')) : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
