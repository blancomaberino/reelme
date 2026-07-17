<?php

namespace App\Services\Places;

use App\Enums\TagKind;
use App\Models\Place;
use App\Models\Tag;
use App\Support\TagTranslations;
use Illuminate\Support\Facades\DB;

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

                // Seed the Spanish label from the dictionary on first creation
                // (ADR-084 #2); an existing tag keeps whatever it already has.
                $tag = Tag::query()->firstOrCreate(
                    ['kind' => $kind, 'slug' => $slug],
                    ['name' => mb_substr($name, 0, 80), 'name_i18n' => TagTranslations::forName($name)],
                );
                $attach[$tag->id] = [
                    'source' => 'extraction',
                    'confidence' => $confidence !== null ? round($confidence, 3) : null,
                ];
            }
        }

        if ($attach !== []) {
            // One atomic upsert: race-safe on the (place_id, tag_id) PK under
            // concurrent publishes, keeps the MAX confidence (never downgrades,
            // no read-then-write window), and never touches the provenance of
            // an existing pivot (a manual/owner tag stays manual/owner).
            $values = [];
            $bindings = [];
            foreach ($attach as $tagId => $pivot) {
                $values[] = '(?, ?, ?, ?)';
                array_push($bindings, $place->id, $tagId, $pivot['source'], $pivot['confidence']);
            }
            DB::statement(
                'insert into place_tag (place_id, tag_id, source, confidence) values '.implode(', ', $values).'
                 on conflict (place_id, tag_id) do update
                 set confidence = nullif(greatest(coalesce(place_tag.confidence, -1), coalesce(excluded.confidence, -1)), -1)',
                $bindings,
            );
        }

        // Backfill the primary cuisine with the raw label, exactly like the
        // resolver (PlaceResolver) does — one format for the exact-match
        // filters — truncated to the column's varchar(64).
        if ($place->cuisine_primary === null && ($label = trim($labels[TagKind::Cuisine->value][0] ?? '')) !== '') {
            $place->cuisine_primary = mb_substr($label, 0, 64);
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
