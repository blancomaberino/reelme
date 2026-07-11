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
            foreach ($names as $name) {
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
            $existing = $place->tags()
                ->whereIn('tags.id', array_keys($attach))
                ->get()
                ->keyBy('id');

            foreach ($attach as $tagId => $pivot) {
                $current = $existing->get($tagId);
                if ($current === null) {
                    $place->tags()->attach($tagId, $pivot);

                    continue;
                }
                // Keep the strongest signal across republs/multiple sources.
                $currentConfidence = $current->getRelationValue('pivot')?->confidence;
                if ($pivot['confidence'] !== null
                    && ($currentConfidence === null || (float) $pivot['confidence'] > (float) $currentConfidence)) {
                    $place->tags()->updateExistingPivot($tagId, ['confidence' => $pivot['confidence']]);
                }
            }
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
