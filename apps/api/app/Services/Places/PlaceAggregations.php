<?php

namespace App\Services\Places;

use App\Models\Place;

/**
 * Cross-source aggregation for a place (T-096), split out of the Place model so
 * the model keeps persistence + relationships + geo I/O + the locked-field API.
 * Every method is pure: it reads the already-loaded `sources` relation (and, for
 * dishes, `sources.dishes`) and issues no queries. Callers MUST eager-load both.
 *
 * The discount label {@see discountCard()} is the PHP twin of the query-side
 * `Place::DISCOUNT_CARD_SQL` (the T-079 card filter + facet). They MUST produce
 * the same label — a shown card must always be a filterable one — and are pinned
 * together by PlaceAggregationsTest's twin-drift test, not comment discipline alone.
 */
class PlaceAggregations
{
    /**
     * Union + dedupe the discovery tags across every place_source's frozen
     * extraction snapshot. `cuisines`/`vibe_tags`/`dietary_tags` are string lists
     * read from the snapshot; `dishes` are {@see App\Models\Dish} rows deduped by
     * name (first wins), read from the table the snapshot is projected into.
     *
     * Iterating the SOURCES rather than the place's dishes directly is what keeps
     * the aggregation honest: `PlaceController::show()` caps the loaded sources
     * and drops the ones belonging to blocked accounts, and dishes must obey the
     * same set — a moderated contribution must not walk back in through its
     * dishes.
     *
     * @return array{cuisines: list<string>, vibe_tags: list<string>, dietary_tags: list<string>, dishes: list<array{name: string, shown_in_video: bool, price: string|null}>}
     */
    public static function tags(Place $place): array
    {
        $cuisines = [];
        $vibeTags = [];
        $dietaryTags = [];
        /** @var array<string, array{name: string, shown_in_video: bool, price: string|null}> $dishes */
        $dishes = [];

        foreach ($place->sources as $source) {
            $snapshot = $source->extraction_snapshot_json;

            foreach (self::stringList($snapshot['cuisines'] ?? null) as $value) {
                $cuisines[$value] = $value;
            }
            foreach (self::stringList($snapshot['vibe_tags'] ?? null) as $value) {
                $vibeTags[$value] = $value;
            }
            foreach (self::stringList($snapshot['dietary_tags'] ?? null) as $value) {
                $dietaryTags[$value] = $value;
            }

            // Dishes come from the first-class `dishes` rows, NOT from
            // `$snapshot['dishes']` (T-157). Same data, same order, same dedupe
            // — the projection is written by DishMaterializer from this very
            // snapshot — but read from the table so there is one dish corpus,
            // and so the thing `?dish=` filters on is the thing the place shows.
            foreach ($source->dishes as $dish) {
                $name = $dish->name;
                $price = $dish->price;

                if (isset($dishes[$name])) {
                    // First occurrence wins for the dish, but a later source
                    // can fill in a price the first one lacked (menu update).
                    if ($dishes[$name]['price'] === null && $price !== null) {
                        $dishes[$name]['price'] = $price;
                    }

                    continue;
                }

                $dishes[$name] = [
                    'name' => $name,
                    'shown_in_video' => $dish->shown_in_video,
                    'price' => $price,
                ];
            }
        }

        return [
            'cuisines' => array_values($cuisines),
            'vibe_tags' => array_values($vibeTags),
            'dietary_tags' => array_values($dietaryTags),
            'dishes' => array_values($dishes),
        ];
    }

    /**
     * Union + dedupe the caption-derived card/bank/wallet discounts across every
     * place_source snapshot (T-079). Each discount's display `card` is the
     * resolved issuer, else the scheme, else the `@handle`; deduped by
     * (card, terms) so two sources repeating the same offer collapse to one.
     *
     * @return list<array{card: string, terms: string, percent: int|null}>
     */
    public static function discounts(Place $place): array
    {
        /** @var array<string, array{card: string, terms: string, percent: int|null}> $discounts */
        $discounts = [];

        foreach ($place->sources as $source) {
            $snapshot = $source->extraction_snapshot_json;
            if (! is_array($snapshot['discounts'] ?? null)) {
                continue;
            }

            foreach ($snapshot['discounts'] as $discount) {
                if (! is_array($discount)) {
                    continue;
                }
                $card = self::discountCard($discount);
                $terms = trim((string) ($discount['terms'] ?? ''));
                if ($card === '' || $terms === '') {
                    continue;
                }
                $percent = is_int($discount['percent'] ?? null) ? $discount['percent'] : null;
                $key = mb_strtolower($card).'|'.mb_strtolower($terms);
                $discounts[$key] ??= ['card' => $card, 'terms' => $terms, 'percent' => $percent];
            }
        }

        return array_values($discounts);
    }

    /**
     * The display label for a raw discount snapshot: resolved issuer, else the
     * card scheme, else the `@handle`. The SQL twin is `Place::DISCOUNT_CARD_SQL`
     * (used by the filter + facet) — keep the two in lockstep, including the
     * leading-`@` collapse, so a shown card is always a filterable one.
     *
     * @param  array<string, mixed>  $discount
     */
    public static function discountCard(array $discount): string
    {
        $issuer = trim((string) ($discount['issuer'] ?? ''));
        if ($issuer !== '') {
            return $issuer;
        }
        $scheme = trim((string) ($discount['scheme'] ?? ''));
        if ($scheme !== '') {
            return $scheme;
        }
        // Strip any leading @ first, then re-prepend — a handle that is only @
        // chars collapses to '' (dropped), matching DISCOUNT_CARD_SQL's NULL.
        $handle = ltrim(trim((string) ($discount['handle'] ?? '')), '@');

        return $handle !== '' ? '@'.$handle : '';
    }

    /**
     * Coerce a snapshot value to a deduped list of non-empty trimmed strings.
     * (Dedup happens in {@see tags()} via the keyed accumulators.)
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }
}
